<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\UniversalExport;
use App\Models\CurrencyExchange;
use App\Models\InternalTransaction;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    // =========================================================================
    //  1. MATRIZ DE RENTABILIDAD (CORREGIDO)
    // =========================================================================
    public function profitMatrix(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = $request->start_date ? $request->start_date . ' 00:00:00' : null;
        $end   = $request->end_date   ? $request->end_date   . ' 23:59:59' : null;

        // 💰 CORRECCIÓN DEFINITIVA: Usamos 'commission_total_amount'
        $profitCalculation = '
            SUM(
                COALESCE(commission_total_amount, 0) - 
                (
                    COALESCE(commission_provider_amount, 0) + 
                    COALESCE(commission_broker_amount, 0) + 
                    COALESCE(commission_admin_amount, 0) +
                    COALESCE(investor_profit_amount, 0)
                )
            ) as total_profit
        ';

        $query = CurrencyExchange::query()
            ->where('status', 'completed')
            ->whereNotNull('from_account_id')
            ->whereNotNull('to_account_id')
            ->selectRaw("
                from_account_id, to_account_id,
                COUNT(*) as total_ops,
                SUM(amount_sent) as total_sent,
                SUM(amount_received) as total_received,
                $profitCalculation
            ")
            ->groupBy('from_account_id', 'to_account_id');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $data = $query->with(['fromAccount', 'toAccount'])
            ->orderByDesc('total_profit')
            ->get();

        $routes = $data->filter(fn($r) => $r->total_received > 0);

        $top15 = $routes->take(15)->map(fn($item) => [
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
                'from_account'      => ['id' => $item->fromAccount?->id, 'name' => $item->fromAccount?->name ?? 'N/A', 'currency_code' => $item->fromAccount?->currency_code ?? '???'],
                'to_account_id'     => $item->to_account_id,
                'to_account'        => ['id' => $item->toAccount?->id, 'name' => $item->toAccount?->name ?? 'N/A', 'currency_code' => $item->toAccount?->currency_code ?? '???'],
                'total_sent'        => round((float) $item->total_sent, 2),
                'total_received'    => round((float) $item->total_received, 2),
                'total_profit'      => round((float) $item->total_profit, 2),
                'total_ops'         => (int) $item->total_ops,
            ]),
            'top_10' => $top15,
            'summary' => [
                'total_routes'       => $routes->count(),
                'total_volume_sent'  => round($routes->sum('total_sent'), 2),
                'total_volume_received' => round($routes->sum('total_received'), 2),
                'total_profit'       => round($routes->sum('total_profit'), 2),
                'total_operations'   => $routes->sum('total_ops'),
                'period' => $request->filled('start_date') ? ['from' => $request->start_date, 'to' => $request->end_date] : null,
            ]
        ]);
    }

    // =========================================================================
    //  2. DESCARGA DE REPORTES
    // =========================================================================

    public function download(Request $request)
    {
        $user = Auth::user();
        if (!$user) abort(401, 'No autenticado');

        $type = $request->input('report_type');
        $format = $request->input('format', 'excel');
        $entityId = $request->input('entity_id');

        $data = collect([]);
        $headers = [];
        $title = "Reporte del Sistema";

        $applyDateFilter = function ($q) use ($request) {
            if ($request->start_date) $q->whereDate('created_at', '>=', $request->start_date);
            if ($request->end_date) $q->whereDate('created_at', '<=', $request->end_date);
        };

        // 💰 CORRECCIÓN DEFINITIVA: Usamos 'commission_total_amount'
        $selectRawLogic = '
            COUNT(*) as total_ops,
            SUM(amount_sent + amount_received) as total_moved,
            SUM(COALESCE(commission_total_amount, 0)) as total_gross, 
            SUM(
                COALESCE(commission_total_amount, 0) - 
                (
                    COALESCE(commission_provider_amount, 0) + 
                    COALESCE(commission_broker_amount, 0) + 
                    COALESCE(commission_admin_amount, 0) +
                    COALESCE(investor_profit_amount, 0)
                )
            ) as total_net
        ';

        switch ($type) {
            case 'clients_summary':
                $title = "Resumen por Cliente";
                $headers = ['Cliente', 'Transacciones', 'Volumen Total', 'Ganancia Bruta', 'Utilidad Neta'];

                $query = CurrencyExchange::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereNotNull('client_id')
                    ->with('client')
                    ->selectRaw('client_id, ' . $selectRawLogic)
                    ->groupBy('client_id')
                    ->orderByDesc('total_moved');
                
                if ($entityId) $query->where('client_id', $entityId);
                $applyDateFilter($query);

                $data = $query->get()->map(fn($item) => [
                    'name'   => $item->client->name ?? 'Cliente Eliminado',
                    'ops'    => $item->total_ops,
                    'vol'    => number_format($item->total_moved, 2),
                    'gross'  => number_format($item->total_gross, 2),
                    'net'    => number_format($item->total_net, 2)
                ]);
                break;

            case 'brokers_summary':
                $title = "Resumen por Corredor";
                $headers = ['Corredor', 'Transacciones', 'Volumen Total', 'Ganancia Bruta', 'Utilidad Neta'];

                $query = CurrencyExchange::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereNotNull('broker_id')
                    ->with('broker')
                    ->selectRaw('broker_id, ' . $selectRawLogic)
                    ->groupBy('broker_id')
                    ->orderByDesc('total_moved');

                if ($entityId) $query->where('broker_id', $entityId);
                $applyDateFilter($query);

                $data = $query->get()->map(fn($item) => [
                    'name'   => $item->broker->name ?? 'Broker Eliminado',
                    'ops'    => $item->total_ops,
                    'vol'    => number_format($item->total_moved, 2),
                    'gross'  => number_format($item->total_gross, 2),
                    'net'    => number_format($item->total_net, 2)
                ]);
                break;

            case 'providers_summary':
                $title = "Resumen por Proveedor";
                $headers = ['Proveedor', 'Transacciones', 'Volumen Total', 'Ganancia Bruta', 'Utilidad Neta'];

                $query = CurrencyExchange::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereNotNull('provider_id')
                    ->with('provider')
                    ->selectRaw('provider_id, ' . $selectRawLogic)
                    ->groupBy('provider_id')
                    ->orderByDesc('total_moved');

                if ($entityId) $query->where('provider_id', $entityId);
                $applyDateFilter($query);

                $data = $query->get()->map(fn($item) => [
                    'name'   => $item->provider->name ?? 'Proveedor Eliminado',
                    'ops'    => $item->total_ops,
                    'vol'    => number_format($item->total_moved, 2),
                    'gross'  => number_format($item->total_gross, 2),
                    'net'    => number_format($item->total_net, 2)
                ]);
                break;

            case 'platforms_summary':
                $title = "Resumen por Plataforma";
                $headers = ['Cuenta Destino', 'Transacciones', 'Volumen Total', 'Ganancia Bruta', 'Utilidad Neta'];

                $query = CurrencyExchange::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereNotNull('to_account_id')
                    ->with('toAccount')
                    ->selectRaw('to_account_id, ' . $selectRawLogic)
                    ->groupBy('to_account_id')
                    ->orderByDesc('total_moved');

                if ($entityId) $query->where('to_account_id', $entityId);
                $applyDateFilter($query);

                $data = $query->get()->map(fn($item) => [
                    'name'   => $item->toAccount->name ?? 'N/A',
                    'ops'    => $item->total_ops,
                    'vol'    => number_format($item->total_moved, 2),
                    'gross'  => number_format($item->total_gross, 2),
                    'net'    => number_format($item->total_net, 2)
                ]);
                break;

            case 'profit_matrix':
                $title = "Matriz de Rentabilidad";
                $headers = ['Ruta (Origen -> Destino)', 'Operaciones', 'Volumen Recibido', 'Ganancia Generada'];

                // 💰 CORRECCIÓN DEFINITIVA: Usamos 'commission_total_amount'
                $query = CurrencyExchange::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereNotNull('from_account_id')
                    ->whereNotNull('to_account_id')
                    ->selectRaw('
                        from_account_id, to_account_id,
                        COUNT(*) as total_ops,
                        SUM(amount_received) as total_received,
                        SUM(
                            COALESCE(commission_total_amount, 0) - 
                            (
                                COALESCE(commission_provider_amount, 0) + 
                                COALESCE(commission_broker_amount, 0) + 
                                COALESCE(commission_admin_amount, 0) +
                                COALESCE(investor_profit_amount, 0)
                            )
                        ) as total_profit
                    ')
                    ->groupBy('from_account_id', 'to_account_id')
                    ->with(['fromAccount', 'toAccount'])
                    ->orderByDesc('total_profit');

                $applyDateFilter($query);

                $data = $query->get()->map(fn($item) => [
                    'route' => ($item->fromAccount->name ?? 'N/A') . ' ➜ ' . ($item->toAccount->name ?? 'N/A'),
                    'ops' => $item->total_ops,
                    'vol' => number_format($item->total_received, 2) . ' ' . ($item->toAccount->currency_code ?? ''),
                    'profit' => number_format($item->total_profit, 2)
                ]);
                break;

            case 'operations':
                $title = "Historial de Operaciones";
                $headers = ['Ref', 'Fecha', 'Cliente', 'Tipo', 'Envía', 'Recibe', 'Tasa', 'Estado', 'Utilidad'];
                $query = CurrencyExchange::with(['client', 'fromAccount', 'toAccount'])
                    ->where('tenant_id', $user->tenant_id)
                    ->orderBy('created_at', 'desc');
                
                if ($request->client_id) $query->where('client_id', $request->client_id);
                if ($request->broker_id) $query->where('broker_id', $request->broker_id);
                $applyDateFilter($query);

                $data = $query->get()->map(function($item) {
                    // 💰 CORRECCIÓN DEFINITIVA: Usamos 'commission_total_amount'
                    $profit = $item->commission_total_amount - (
                        $item->commission_provider_amount + 
                        $item->commission_broker_amount + 
                        $item->commission_admin_amount +
                        $item->investor_profit_amount
                    );

                    return [
                        'ref'    => $item->number ?? $item->id,
                        'date'   => $item->created_at->format('Y-m-d H:i'),
                        'client' => $item->client->name ?? 'N/A',
                        'type'   => strtoupper($item->type),
                        'sent'   => number_format($item->amount_sent, 2) . ' ' . ($item->fromAccount->currency_code ?? ''),
                        'recv'   => number_format($item->amount_received, 2) . ' ' . ($item->toAccount->currency_code ?? ''),
                        'rate'   => $item->exchange_rate,
                        'status' => strtoupper($item->status),
                        'profit' => number_format($profit, 2)
                    ];
                });
                break;

            case 'internal':
            case 'cash_flow':
                $title = "Reporte de Caja y Gastos";
                $headers = ['Fecha', 'Tipo', 'Categoría', 'Cuenta / Billetera', 'Monto', 'Descripción'];

                $query = InternalTransaction::with(['account', 'entity'])
                    ->where('tenant_id', $user->tenant_id)
                    ->orderBy('transaction_date', 'desc');

                if ($request->start_date) $query->whereDate('transaction_date', '>=', $request->start_date);
                if ($request->end_date) $query->whereDate('transaction_date', '<=', $request->end_date);

                $data = $query->get()->map(function ($item) {
                    $accName = 'Cuenta Eliminada';
                    if ($item->account) {
                        $accName = $item->account->name . ' (' . $item->account->currency_code . ')';
                    } elseif ($item->entity_type && str_contains($item->entity_type, 'Provider')) {
                        $realName = $item->entity ? $item->entity->name : ($item->person_name ?? 'Proveedor');
                        $accName = "Billetera: $realName";
                    } elseif ($item->entity_type && str_contains($item->entity_type, 'Investor')) {
                        $realName = $item->entity ? $item->entity->name : ($item->person_name ?? 'Inversionista');
                        $accName = "Capital: $realName";
                    } elseif ($item->person_name) {
                        $accName = "Virtual: " . $item->person_name;
                    }

                    return [
                        'date' => $item->transaction_date,
                        'type' => $item->type === 'income' ? 'INGRESO' : 'EGRESO',
                        'cat'  => $item->category,
                        'acc'  => $accName,
                        'amt'  => number_format($item->amount, 2),
                        'desc' => $item->description
                    ];
                });
                break;

            case 'payables':
            case 'receivables':
                $isPayable = ($type === 'payables');
                $title = $isPayable ? "Cuentas por Pagar" : "Cuentas por Cobrar";
                $headers = ['Vencimiento', 'Entidad', 'Descripción', 'Monto Total', 'Pagado', 'Pendiente'];
                $query = LedgerEntry::where('type', $isPayable ? 'payable' : 'receivable')->where('tenant_id', $user->tenant_id)->with('entity')->orderBy('due_date', 'asc');
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
                Log::warning("Reporte solicitado con tipo desconocido: " . $type);
                $data = collect([]);
        }

        if ($format === 'excel') {
            return Excel::download(new UniversalExport($data, $headers), "{$type}_" . date('Ymd') . ".xlsx");
        }

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.generic_pdf', [
                'title' => $title,
                'headers' => $headers,
                'data' => $data,
                'companyName' => $user->tenant->name ?? 'Khepas',
                'dateRange' => ($request->start_date ? $request->start_date : 'Inicio') . ' al ' . ($request->end_date ? $request->end_date : 'Hoy')
            ]);
            return $pdf->setPaper('a4', 'landscape')->download("{$type}_" . date('Ymd') . ".pdf");
        }
    }
}