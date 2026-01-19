<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function getSummary()
    {
        // 1. Caja General
        $caja_general = Account::select('currency_code', DB::raw('SUM(balance) as total_balance'))
            ->groupBy('currency_code')
            ->get();

        // 2. Por Pagar (Pasivos Reales)
        // Sumamos directamente del Ledger, donde se guardó la deuda al desmarcar "Pagado"
        $por_pagar = LedgerEntry::where('type', 'payable')
            ->whereIn('status', ['pending', 'partially_paid'])
            ->sum(DB::raw('amount - paid_amount'));

        // 3. Por Cobrar (Activos Reales)
        $por_cobrar_total = LedgerEntry::where('type', 'receivable')
            ->whereIn('status', ['pending', 'partially_paid'])
            ->sum(DB::raw('amount - paid_amount'));

        // 4. Balance General
        $caja_total_usd = $caja_general->firstWhere('currency_code', 'USD')?->total_balance ?? 0;
        $balance_general = ($caja_total_usd + $por_cobrar_total) - $por_pagar;

        return response()->json([
            'caja_general_por_moneda' => $caja_general,
            'total_por_pagar'         => round($por_pagar, 2),
            'total_por_cobrar'        => round($por_cobrar_total, 2),
            'desglose_por_cobrar'     => [
                'ledger' => round($por_cobrar_total, 2),
                'compras_pendientes' => 0 
            ],
            'balance_general_usd'     => round($balance_general, 2),
        ]);
    }
}