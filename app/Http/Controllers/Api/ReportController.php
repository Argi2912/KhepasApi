<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// 1. LIBRERÍAS DE EXPORTACIÓN
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\UniversalExport; 

// 2. TUS MODELOS
use App\Models\CurrencyExchange;
use App\Models\InternalTransaction;
use App\Models\LedgerEntry;

class ReportController extends Controller
{
    // =========================================================================
    //  1. MATRIZ DE RENTABILIDAD (Tu lógica original intacta)
    // =========================================================================
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
            ->orderByDesc('total_received')
            ->get();

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

    // =========================================================================
    //  2. DESCARGA DE REPORTES (Esta es la función que te faltaba)
    // =========================================================================
    public function download(Request $request)
    {
        $type = $request->input('report_type');
        $format = $request->input('format', 'excel');
        
        $data = collect([]);
        $headers = [];
        $title = "Reporte del Sistema";

        // --- SELECCIÓN DE DATOS ---
        switch ($type) {
            case 'operations':
                $title = "Historial de Operaciones";
                $headers = ['Ref', 'Fecha', 'Cliente', 'Tipo', 'Envía', 'Recibe', 'Tasa', 'Estado'];
                
                $query = CurrencyExchange::with(['client', 'user'])->orderBy('created_at', 'desc');
                
                if ($request->client_id) $query->where('client_id', $request->client_id);
                if ($request->broker_id) $query->where('broker_id', $request->broker_id);
                if ($request->start_date) $query->whereDate('created_at', '>=', $request->start_date);
                if ($request->end_date) $query->whereDate('created_at', '<=', $request->end_date);
                
                $data = $query->get()->map(fn($item) => [
                    'ref' => $item->number ?? $item->id,
                    'date' => $item->created_at->format('Y-m-d H:i'),
                    'client' => $item->client->name ?? 'N/A',
                    'type' => ($item->buy_rate > 0) ? 'COMPRA' : 'INTERCAMBIO',
                    'sent' => number_format($item->amount_sent, 2),
                    'recv' => number_format($item->amount_received, 2),
                    'rate' => $item->exchange_rate,
                    'status' => strtoupper($item->status ?? 'pending')
                ]);
                break;

            case 'cash_flow':
                $title = "Reporte de Caja y Gastos";
                $headers = ['Fecha', 'Tipo', 'Categoría', 'Cuenta', 'Monto', 'Descripción'];
                
                $query = InternalTransaction::with('account')->orderBy('transaction_date', 'desc');
                
                if ($request->start_date) $query->whereDate('transaction_date', '>=', $request->start_date);
                if ($request->end_date) $query->whereDate('transaction_date', '<=', $request->end_date);

                $data = $query->get()->map(fn($item) => [
                    'date' => $item->transaction_date,
                    'type' => $item->type === 'income' ? 'INGRESO' : 'EGRESO',
                    'cat'  => $item->category,
                    'acc'  => $item->account->name ?? '---',
                    'amt'  => number_format($item->amount, 2),
                    'desc' => $item->description
                ]);
                break;

            case 'payables': 
            case 'receivables':
                $isPayable = ($type === 'payables');
                $title = $isPayable ? "Cuentas por Pagar" : "Cuentas por Cobrar";
                $headers = ['Vencimiento', 'Entidad', 'Descripción', 'Monto Total', 'Pagado', 'Pendiente'];
                
                $query = LedgerEntry::where('type', $isPayable ? 'payable' : 'receivable')
                    ->with('entity')
                    ->orderBy('due_date', 'asc');

                $data = $query->get()->map(fn($item) => [
                    'due' => $item->due_date,
                    'ent' => $item->entity->name ?? 'N/A',
                    'desc' => $item->description,
                    'total' => number_format($item->amount, 2),
                    'paid' => number_format($item->paid_amount, 2),
                    'balance' => number_format($item->amount - $item->paid_amount, 2)
                ]);
                break;
                
            default:
                $data = collect([]);
        }

        // --- GENERACIÓN DEL ARCHIVO ---
        if ($format === 'excel') {
            // Genera XLSX (Compatible con Google Sheets)
            return Excel::download(new UniversalExport($data, $headers), "{$type}_" . date('Ymd') . ".xlsx");
        } 
        
        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.generic_pdf', [
                'title' => $title,
                'headers' => $headers,
                'data' => $data,
                'companyName' => 'Khepas'
            ]);
            return $pdf->setPaper('a4', 'landscape')->download("{$type}_" . date('Ymd') . ".pdf");
        }
    }
}