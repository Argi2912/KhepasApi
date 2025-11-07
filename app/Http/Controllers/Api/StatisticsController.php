<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DollarPurchase;
use App\Models\CurrencyExchange;
use App\Models\Broker;
use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StatisticsController extends Controller
{
    /**
     * Devuelve estadísticas filtrables y KPIs (Módulo 5).
     */
    public function getPerformance(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'broker_id' => 'nullable|exists:brokers,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);
        
        $tenantId = Auth::guard('api')->user()->tenant_id;

        // --- 1. KPIs (Conteos Totales del Tenant) ---
        // El TenantScope se aplica automáticamente a estos modelos
        $kpis = [
            'total_clients' => Client::count(),
            'total_providers' => Provider::count(),
            'total_brokers' => Broker::count(),
            'total_users' => User::where('tenant_id', $tenantId)->count(), // User no tiene Scope global
        ];


        // --- 2. Producción por Corredor (Ranking) ---
        // (Usando DollarPurchase como ejemplo de volumen)
        $production_by_broker = Broker::query()
            ->join('dollar_purchases', 'brokers.id', '=', 'dollar_purchases.broker_id')
            ->join('users', 'brokers.user_id', '=', 'users.id')
            ->select('brokers.id', DB::raw('COALESCE(brokers.name, users.name) as broker_name'), DB::raw('SUM(dollar_purchases.amount_received) as total_volume'))
            ->when($request->start_date, fn($q) => $q->whereDate('dollar_purchases.created_at', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->whereDate('dollar_purchases.created_at', '<=', $request->end_date))
            ->when($request->client_id, fn($q) => $q->where('dollar_purchases.client_id', $request->client_id))
            ->when($request->broker_id, fn($q) => $q->where('brokers.id', $request->broker_id))
            ->groupBy('brokers.id', 'broker_name')
            ->orderBy('total_volume', 'desc')
            ->get();
            
            
        // --- 3. Registros de Usuarios (Mensual) ---
        $user_registrations_monthly = User::query()
            ->where('tenant_id', $tenantId) // Filtro manual para User
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(id) as count')
            )
            ->when($request->start_date, fn($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->whereDate('created_at', '<=', $request->end_date))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();
            

        // --- 4. Ganancias Mensuales (Simple) ---
        // Definición de "Ganancia": Suma de 'commission_admin_pct' de CurrencyExchange
        $monthly_profits = CurrencyExchange::query()
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(amount_received * (commission_admin_pct / 100)) as total_profit')
            )
            ->when($request->start_date, fn($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->whereDate('created_at', '<=', $request->end_date))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();


        // --- 5. Volumen Total Procesado (General) ---
        $total_volume_exchange = CurrencyExchange::query()
            ->when($request->start_date, fn($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->whereDate('created_at', '<=', $request->end_date))
            ->sum('amount_received');
            
        $total_volume_purchase = DollarPurchase::query()
            ->when($request->start_date, fn($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->whereDate('created_at', '<=', $request->end_date))
            ->sum('amount_received');

        
        return response()->json([
            'kpis' => $kpis,
            'filters' => $request->only(['start_date', 'end_date', 'broker_id', 'client_id']),
            'total_volume' => [
                'exchange' => $total_volume_exchange,
                'purchase' => $total_volume_purchase,
            ],
            'metrics' => [
                'production_by_broker' => $production_by_broker,
                'user_registrations_monthly' => $user_registrations_monthly,
                'monthly_profits_simple' => $monthly_profits,
            ]
        ]);
    }
}