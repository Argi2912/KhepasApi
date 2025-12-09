<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchange;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function profitMatrix(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = $request->start_date ? $request->start_date . ' 00:00:00' : null;
        $end   = $request->end_date   ? $request->end_date   . ' 23:59:59' : null;

        $query = CurrencyExchange::query()
            ->where('status', 'completed')
            ->whereNotNull('from_account_id')
            ->whereNotNull('to_account_id')
            ->selectRaw('
                from_account_id,
                to_account_id,
                COUNT(*) as total_ops,
                SUM(amount_sent) as total_sent,
                SUM(amount_received) as total_received,
                SUM(COALESCE(commission_admin_amount, 0)) as total_profit
            ')
            ->groupBy('from_account_id', 'to_account_id');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $data = $query->with([
                'fromAccount:id,name,currency_code',
                'toAccount:id,name,currency_code'
            ])
            ->orderByDesc('total_received') // Ordenamos por volumen de entrada
            ->get();

        // Solo mostramos rutas que realmente MOVIERON dinero
        $routes = $data->filter(fn($r) => $r->total_received > 0);

        $top10 = $routes->take(10)->map(fn($item) => [
            'source'           => $item->fromAccount?->name ?? 'N/A',
            'source_currency'  => $item->fromAccount?->currency_code ?? '???',
            'destination'      => $item->toAccount?->name ?? 'N/A',
            'dest_currency'    => $item->toAccount?->currency_code ?? '???',
            'volume_sent'      => round((float) $item->total_sent, 2),
            'volume_received'  => round((float) $item->total_received, 2),
            'profit'           => round((float) $item->total_profit, 2),
            'operations'       => (int) $item->total_ops,
        ])->values();

        return response()->json([
            'matrix_data' => $routes->values()->map(fn($item) => [
                'from_account_id'   => $item->from_account_id,
                'from_account'      => [
                    'id'            => $item->fromAccount?->id,
                    'name'          => $item->fromAccount?->name ?? 'N/A',
                    'currency_code' => $item->fromAccount?->currency_code ?? '???',
                ],
                'to_account_id'     => $item->to_account_id,
                'to_account'        => [
                    'id'            => $item->toAccount?->id,
                    'name'          => $item->toAccount?->name ?? 'N/A',
                    'currency_code' => $item->toAccount?->currency_code ?? '???',
                ],
                'total_sent'        => round((float) $item->total_sent, 2),
                'total_received'    => round((float) $item->total_received, 2),
                'total_profit'      => round((float) $item->total_profit, 2),
                'total_ops'         => (int) $item->total_ops,
            ]),
            'top_10' => $top10,
            'summary' => [
                'total_routes'       => $routes->count(),
                'total_volume_sent'  => round($routes->sum('total_sent'), 2),
                'total_volume_received' => round($routes->sum('total_received'), 2),
                'total_profit'       => round($routes->sum('total_profit'), 2),
                'total_operations'   => $routes->sum('total_ops'),
                'period' => $request->filled('start_date') && $request->filled('end_date')
                    ? ['from' => $request->start_date, 'to' => $request->end_date]
                    : null,
            ]
        ]);
    }
}