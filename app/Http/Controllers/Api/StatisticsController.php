<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalTransaction;
use App\Models\CurrencyExchange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function getPerformance(Request $request)
    {
        // 1. Definir rango de fechas (Por defecto: Año actual)
        $year = $request->input('year', date('Y'));
        $startDate = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $endDate = Carbon::createFromDate($year, 12, 31)->endOfYear();

        // ---------------------------------------------------------
        // A. INGRESOS (Income)
        // ---------------------------------------------------------
        
        // A1. Ingresos por Movimientos Internos (Caja)
        $internalIncome = InternalTransaction::selectRaw('MONTH(transaction_date) as month, SUM(amount) as total')
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // A2. Ingresos por Comisiones de Cambios (La ganancia del negocio)
        // Sumamos commission_total_amount (lo que se cobró al cliente)
        // Ojo: Si quieres descontar lo que pagaste al provider, usa (commission_total_amount - commission_provider_amount)
        $exchangeIncome = CurrencyExchange::selectRaw('MONTH(created_at) as month, SUM(commission_total_amount) as total')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // ---------------------------------------------------------
        // B. EGRESOS (Expenses)
        // ---------------------------------------------------------

        // B1. Gastos Operativos (Sueldos, Servicios, Alquiler)
        $expenses = InternalTransaction::selectRaw('MONTH(transaction_date) as month, SUM(amount) as total')
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // ---------------------------------------------------------
        // C. PROCESAMIENTO DE DATOS PARA CHART.JS
        // ---------------------------------------------------------

        $labels = [];
        $dataIncome = [];
        $dataExpense = [];
        $netProfit = [];

        // Iteramos los 12 meses para llenar ceros donde no hubo movimiento
        for ($i = 1; $i <= 12; $i++) {
            $monthName = Carbon::create()->month($i)->translatedFormat('F'); // Enero, Febrero...
            $labels[] = ucfirst($monthName);

            // Sumar Ingresos Internos + Comisiones de Cambios
            $inc = ($internalIncome[$i] ?? 0) + ($exchangeIncome[$i] ?? 0);
            $exp = $expenses[$i] ?? 0;

            $dataIncome[] = round($inc, 2);
            $dataExpense[] = round($exp, 2);
            $netProfit[] = round($inc - $exp, 2);
        }

        // ---------------------------------------------------------
        // D. DATOS ADICIONALES (Distribución de Gastos)
        // ---------------------------------------------------------
        $expensesByCategory = InternalTransaction::selectRaw('category, SUM(amount) as total')
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(5) // Top 5 categorías
            ->get();

        return response()->json([
            'chart_data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Ingresos Totales',
                        'data' => $dataIncome,
                        'backgroundColor' => '#10B981', // Verde
                        'borderColor' => '#10B981',
                    ],
                    [
                        'label' => 'Gastos Operativos',
                        'data' => $dataExpense,
                        'backgroundColor' => '#EF4444', // Rojo
                        'borderColor' => '#EF4444',
                    ]
                ]
            ],
            'summary' => [
                'total_income' => array_sum($dataIncome),
                'total_expense' => array_sum($dataExpense),
                'total_profit' => array_sum($netProfit),
            ],
            'expenses_by_category' => $expensesByCategory
        ]);
    }
}