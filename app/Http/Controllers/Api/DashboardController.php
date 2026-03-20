<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function getSummary()
    {
        $tenantId = Auth::user()->tenant_id;

        // 1. Obtener CAJA (Agrupado por moneda)
        $caja = Account::where('tenant_id', $tenantId)
            ->select('currency_code', DB::raw('SUM(balance) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        // 2. Obtener DEUDAS (Pasivos)
        $por_pagar = LedgerEntry::where('tenant_id', $tenantId)
            ->where('type', 'payable')
            ->whereIn('status', ['pending', 'partially_paid'])
            ->select('currency_code', DB::raw('SUM(amount - paid_amount) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        // 3. Obtener CUENTAS POR COBRAR (Activos)
        $por_cobrar = LedgerEntry::where('tenant_id', $tenantId)
            ->where('type', 'receivable')
            ->whereIn('status', ['pending', 'partially_paid'])
            ->select('currency_code', DB::raw('SUM(amount - paid_amount) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        // 4. Consolidar todo en un Balance Desglosado
        // Obtenemos una lista única de todas las monedas que tienen algún movimiento
        $all_currencies = $caja->keys()
            ->merge($por_pagar->keys())
            ->merge($por_cobrar->keys())
            ->unique()
            ->values();

        $balance_desglosado = [];

        foreach ($all_currencies as $code) {
            $monto_caja      = $caja[$code] ?? 0;
            $monto_pagar     = $por_pagar[$code] ?? 0;
            $monto_cobrar    = $por_cobrar[$code] ?? 0;
            
            // Fórmula Financiera: Activos (Caja + Por Cobrar) - Pasivos (Por Pagar)
            $neto = ($monto_caja + $monto_cobrar) - $monto_pagar;

            // Solo agregamos a la lista si hay algún saldo relevante (evitamos mostrar monedas en 0 total)
            if ($monto_caja != 0 || $monto_pagar != 0 || $monto_cobrar != 0) {
                $balance_desglosado[] = [
                    'currency_code' => $code,
                    'caja'          => (float) $monto_caja,
                    'por_cobrar'    => (float) $monto_cobrar,
                    'por_pagar'     => (float) $monto_pagar,
                    'balance_neto'  => (float) $neto,
                ];
            }
        }

        $accounts_breakdown = Account::where('tenant_id', $tenantId)
            ->select('name as bank_name', 'currency_code', 'balance')
            ->orderBy('currency_code')
            ->get();

        return response()->json([
            'breakdown' => $balance_desglosado,
            'accounts_breakdown' => $accounts_breakdown
        ]);
    }
}