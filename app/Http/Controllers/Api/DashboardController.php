<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\CurrencyExchange; // <--- 1. IMPORTANTE: Agregar el modelo
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
        
        // 2. Por Pagar (Deudas a Proveedores/Brokers en Ledger)
        $por_pagar = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->sum('amount');
            
        // 3. Por Cobrar (Base: Deudas de Clientes en Ledger)
        $por_cobrar_ledger = LedgerEntry::where('type', 'receivable')
            ->where('status', 'pending')
            ->sum('amount');

        // --- NUEVA LÓGICA ---
        // Sumar Compras de Divisas que están Pendientes por Entrega.
        // Identificamos "Compra" si tiene 'buy_rate' > 0.
        // Usamos 'amount_received' porque es el monto en Divisa (USD) que "debemos" cobrar/entregar.
        $compras_pendientes = CurrencyExchange::where('status', 'pending')
            ->whereNotNull('buy_rate')
            ->where('buy_rate', '>', 0)
            ->sum('amount_received');

        // Total Por Cobrar Unificado
        $por_cobrar_total = $por_cobrar_ledger + $compras_pendientes;


        // Asumimos que el "Total" de la caja es la suma de USD (o la moneda principal)
        $caja_total_usd = $caja_general->firstWhere('currency_code', 'USD')?->total_balance ?? 0;
            
        // 4. Balance General (Simple)
        // (Caja + Lo que me deben) - (Lo que debo)
        $balance_general = ($caja_total_usd + $por_cobrar_total) - $por_pagar;

        return response()->json([
            'caja_general_por_moneda' => $caja_general,
            'total_por_pagar' => $por_pagar,
            'total_por_cobrar' => $por_cobrar_total, // Enviamos el total sumado
            'desglose_por_cobrar' => [ // Opcional: por si quieres mostrar el detalle en el front
                'ledger' => $por_cobrar_ledger,
                'compras_pendientes' => $compras_pendientes
            ],
            'balance_general_usd' => $balance_general,
        ]);
    }
}