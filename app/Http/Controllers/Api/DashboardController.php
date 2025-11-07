<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Devuelve el resumen para la Página de Inicio (Módulo 1).
     */
    public function getSummary()
    {
        // El TenantScope se aplica automáticamente a todos estos modelos

        // 1. Caja General (Agrupada por moneda)
        $caja_general = Account::select('currency_code', DB::raw('SUM(balance) as total_balance'))
            ->groupBy('currency_code')
            ->get();
        
        // 2. Por Pagar
        $por_pagar = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->sum('amount');
            
        // 3. Por Cobrar
        $por_cobrar = LedgerEntry::where('type', 'receivable')
            ->where('status', 'pending')
            ->sum('amount');

        // Asumimos que el "Total" de la caja es la suma de USD (o la moneda principal)
        $caja_total_usd = $caja_general->firstWhere('currency_code', 'USD')?->total_balance ?? 0;
            
        // 4. Balance General (Simple)
        $balance_general = ($caja_total_usd + $por_cobrar) - $por_pagar;

        return response()->json([
            'caja_general_por_moneda' => $caja_general,
            'total_por_pagar' => $por_pagar,
            'total_por_cobrar' => $por_cobrar,
            'balance_general_usd' => $balance_general,
        ]);
    }
}