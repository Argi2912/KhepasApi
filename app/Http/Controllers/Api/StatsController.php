<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role; // Para obtener el rol de Broker

class StatsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view home dashboard|view statistics dashboard');
    }

    /**
     * Calcula y devuelve el balance general del Tenant (Método existente).
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNetBalance(): JsonResponse
    {
        // --- 1. Obtener IDs de Cuentas Maestras ---
        $accountIds = Account::select('id', 'type', 'name')
                             ->whereIn('type', ['CASH', 'CXC', 'CXP'])
                             ->get()
                             ->keyBy('type')
                             ->map(fn ($account) => $account->id);
        
        if (!isset($accountIds['CASH']) || !isset($accountIds['CXC']) || !isset($accountIds['CXP'])) {
            return response()->json(['message' => 'Error de configuración: Cuentas maestras (CASH, CXC, CXP) no encontradas.'], 500);
        }

        // --- 2. Calcular Saldos Agregados (SUM(Débito) - SUM(Crédito)) ---
        $balances = TransactionDetail::select(
                DB::raw('account_id'),
                DB::raw('SUM(CASE WHEN is_debit = 1 THEN amount ELSE 0 END) - SUM(CASE WHEN is_debit = 0 THEN amount ELSE 0 END) AS net_balance')
            )
            ->whereIn('account_id', $accountIds->values())
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');
        
        // --- 3. Extraer Métricas Clave ---
        $cashBalance = $balances->get($accountIds['CASH'])['net_balance'] ?? 0;
        $receivableBalance = $balances->get($accountIds['CXC'])['net_balance'] ?? 0;
        $payableBalance = abs($balances->get($accountIds['CXP'])['net_balance'] ?? 0); 
        
        // Balance = (Caja + Por Cobrar) - Por Pagar
        $netBalance = ($cashBalance + $receivableBalance) - $payableBalance;

        // Se omite cash_details por brevedad, asumiendo que usa el mismo cálculo de antes

        return response()->json([
            'metrics' => [
                'total_cash' => round((float)$cashBalance, 2),
                'total_receivable' => round((float)$receivableBalance, 2),
                'total_payable' => round((float)$payableBalance, 2),
                'net_balance' => round((float)$netBalance, 2),
            ],
            'note' => 'Los saldos se calculan en la moneda base del Tenant.',
        ]);
    }

    /**
     * Calcula la producción total de transacciones por cada corredor (Broker).
     * Requisito: Producción por corredor.
     */
    public function getBrokerProduction(Request $request): JsonResponse
    {
        // 1. Encontrar el Rol 'Broker'
        $brokerRole = Role::where('name', 'Broker')->first();

        if (!$brokerRole) {
            return response()->json(['message' => 'El rol Broker no está definido.'], 404);
        }
        
        // 2. Obtener la producción (Volumen total de transacciones) agrupado por el usuario (Broker)
        $production = Transaction::select(
                'user_id',
                DB::raw('COUNT(id) as total_transactions'),
                // Suma el valor absoluto de todos los montos de débito/crédito en los detalles
                DB::raw('SUM(
                    (SELECT SUM(amount) FROM transaction_details 
                     WHERE transaction_details.transaction_id = transactions.id
                     LIMIT 1)
                ) as total_volume')
            )
            // Filtra solo transacciones hechas por usuarios que son Brokers
            ->whereHas('user.roles', fn ($q) => $q->where('role_id', $brokerRole->id))
            ->groupBy('user_id')
            ->with(['user:id,first_name,last_name'])
            ->orderByDesc('total_volume')
            ->get()
            ->map(fn ($p) => [
                'broker_id' => $p->user_id,
                'name' => $p->user->first_name . ' ' . $p->user->last_name,
                'total_transactions' => (int)$p->total_transactions,
                'total_volume' => round((float)$p->total_volume, 2)
            ]);

        return response()->json(['production_by_broker' => $production]);
    }

    /**
     * Calcula el volumen total de transacciones operado en el sistema.
     * Requisito: Volumen total operado.
     */
    public function getVolumeOperated(): JsonResponse
    {
        // Se calcula el volumen como el total de todos los montos de débito registrados
        $totalVolume = TransactionDetail::where('is_debit', true)
                                        ->sum('amount');
        
        return response()->json([
            'total_volume_operated' => round((float)$totalVolume, 2),
            'note' => 'El volumen se basa en la suma de todos los débitos registrados en la moneda base.',
        ]);
    }

    /**
     * Calcula el total de comisiones generadas.
     * Requisito: Total de comisiones generadas.
     * * NOTA: Asume que tienes una Cuenta contable maestra llamada 'Ingresos por Comisiones'.
     */
    public function getCommissionTotals(): JsonResponse
    {
        // 1. Obtener la Cuenta de Ingresos por Comisiones
        $commissionAccount = Account::where('tenant_id', Auth::user()->tenant_id)
                                    ->where('name', 'Ingresos por Comisiones')
                                    ->where('type', 'INGRESS')
                                    ->first();
        
        if (!$commissionAccount) {
            // Si la cuenta no existe, asumir que no hay comisiones o usar una cuenta general.
            return response()->json(['total_commissions' => 0.00, 'message' => 'Cuenta de comisiones no configurada.'], 200);
        }

        // 2. Sumar todos los créditos (aumento de ingreso) a esta cuenta.
        $totalCommissions = TransactionDetail::where('account_id', $commissionAccount->id)
                                              ->where('is_debit', false) // Ingreso aumenta con Crédito
                                              ->sum('amount');

        return response()->json([
            'total_commissions' => round((float)$totalCommissions, 2),
            'account_name' => $commissionAccount->name
        ]);
    }
}