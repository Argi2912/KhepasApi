<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Models\Client;
use App\Models\CurrencyExchange;
use App\Models\InternalTransaction;
use App\Models\Platform;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function getPerformance(Request $request)
    {
        $period     = $request->input('period', 'year'); // day, week, month, year
        $date       = $request->input('date', now()->format('Y-m-d'));
        $carbonDate = Carbon::parse($date);

        // Definir rango y formato
        switch ($period) {
            case 'day':
                $startDate    = $carbonDate->copy()->startOfDay();
                $endDate      = $carbonDate->copy()->endOfDay();
                $groupFormat  = '%H'; // Hora
                $labelFormat  = 'H:i';
                $totalPeriods = 24;
                break;
            case 'week':
                $startDate    = $carbonDate->copy()->startOfWeek();
                $endDate      = $carbonDate->copy()->endOfWeek();
                $groupFormat  = '%Y-%m-%d';
                $labelFormat  = 'l';
                $totalPeriods = 7;
                break;
            case 'month':
                $startDate    = $carbonDate->copy()->startOfMonth();
                $endDate      = $carbonDate->copy()->endOfMonth();
                $groupFormat  = '%d';
                $labelFormat  = 'd';
                $totalPeriods = $carbonDate->daysInMonth;
                break;
            case 'year':
            default:
                $startDate    = $carbonDate->copy()->startOfYear();
                $endDate      = $carbonDate->copy()->endOfYear();
                $groupFormat  = '%m';
                $labelFormat  = 'F';
                $totalPeriods = 12;
                break;
        }

        // INGRESOS
        $internalIncome = InternalTransaction::selectRaw("DATE_FORMAT(transaction_date, ?) as period, SUM(amount) as total", [$groupFormat])
            ->where('type', InternalTransaction::TYPE_INCOME)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        $exchangeIncome = CurrencyExchange::selectRaw("DATE_FORMAT(created_at, ?) as period, SUM(commission_total_amount) as total", [$groupFormat])
            ->completed()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        // GASTOS
        $expenses = InternalTransaction::selectRaw("DATE_FORMAT(transaction_date, ?) as period, SUM(amount) as total", [$groupFormat])
            ->where('type', InternalTransaction::TYPE_EXPENSE)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        // Generar datos
        $labels      = [];
        $dataIncome  = [];
        $dataExpense = [];

        for ($i = 0; $i < $totalPeriods; $i++) {
            if ($period === 'day') {
                $current = $carbonDate->copy()->startOfDay()->addHours($i);
                $key     = $current->format('H');
                $label   = $current->format('H:i');
            } elseif ($period === 'week') {
                $current = $startDate->copy()->addDays($i);
                $key     = $current->format('Y-m-d');
                $label   = $current->translatedFormat('l');
            } elseif ($period === 'month') {
                $current = $startDate->copy()->addDays($i);
                $key     = $current->format('d');
                $label   = $current->format('d');
            } else {
                $current = Carbon::create($carbonDate->year, $i + 1, 1);
                $key     = $current->format('m');
                $label   = $current->translatedFormat('F');
            }

            $labels[] = $label;
            $income   = ($internalIncome[$key] ?? 0) + ($exchangeIncome[$key] ?? 0);
            $expense  = $expenses[$key] ?? 0;

            $dataIncome[]  = round($income, 2);
            $dataExpense[] = round($expense, 2);
        }

        $totalIncome  = array_sum($dataIncome);
        $totalExpense = array_sum($dataExpense);
        $totalProfit  = $totalIncome - $totalExpense;

        // Gastos por categoría (solo mes/año)
        $expensesByCategory = in_array($period, ['month', 'year'])
            ? InternalTransaction::selectRaw('category, SUM(amount) as total')
            ->where('type', InternalTransaction::TYPE_EXPENSE)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->groupBy('category')
            ->orderByDesc('total')
            ->get(['category', 'total'])
            : collect();

        return response()->json([
            'chart_data'           => [
                'labels'   => $labels,
                'datasets' => [
                    [
                        'label'           => 'Ingresos',
                        'data'            => $dataIncome,
                        'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
                        'borderColor'     => '#22c55e',
                    ],
                    [
                        'label'           => 'Gastos',
                        'data'            => $dataExpense,
                        'backgroundColor' => 'rgba(239, 68, 68, 0.6)',
                        'borderColor'     => '#ef4444',
                    ],
                ],
            ],
            'summary'              => [
                'total_income'  => round($totalIncome, 2),
                'total_expense' => round($totalExpense, 2),
                'total_profit'  => round($totalProfit, 2),
            ],
            'expenses_by_category' => $expensesByCategory,
        ]);
    }

    // Reporte por entidad unificado y optimizado
    private function getEntityReport($entityType, Request $request)
    {
        $startDate = $request->input('start_date', Carbon::now()->startOfYear());
        $endDate   = $request->input('end_date', Carbon::now()->endOfYear());
        $entityId  = $request->input('entity_id');

        // Normalizar fechas
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate   = Carbon::parse($endDate)->endOfDay();

        // Configuración según el tipo de entidad
        switch ($entityType) {
            case 'client':
                $model         = Client::class;
                $relationField = 'client_id';
                break;
            case 'provider':
                $model         = Provider::class;
                $relationField = 'provider_id';
                break;
            case 'platform':
                $model         = Platform::class;
                $relationField = 'platform_id';
                break;
            case 'broker':
                $model         = Broker::class;
                $relationField = 'broker_id';
                break;
            default:
                return response()->json(['error' => 'Tipo de entidad inválido'], 400);
        }

        // Obtener entidades (filtrando si se envió ID)
        $query = $model::query();
        if ($entityId) {
            $query->where('id', $entityId);
        }
        $entities = $query->get();

        $reports = [];

        foreach ($entities as $entity) {
            // Consulta optimizada: Sumar directamente en la DB en lugar de traer los registros y sumar en PHP
            // Usamos los campos correctos de CurrencyExchange
            $stats = CurrencyExchange::where($relationField, $entity->id)
                ->completed() // Scope: status = 'completed'
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*) as total_transactions,
                    SUM(commission_total_amount) as total_gross_profit,
                    SUM(commission_admin_amount) as total_net_profit,
                    SUM(amount_sent + amount_received) as total_volume_moved
                ')
                ->first();

            // Si no hay movimientos, saltamos o ponemos ceros (opcional)
            if (! $stats || $stats->total_transactions == 0) {
                continue; // O puedes agregarlo con ceros si prefieres mostrar todos
            }

            // Mapeo de datos para el reporte
            $reports[] = [
                'entity_id'          => $entity->id,
                'entity_name'        => $entity->name ?? $entity->alias ?? 'Sin Nombre', // Ajustar según tus modelos

                // Ganancia generada (Bruta de la operación)
                'total_profit'       => round($stats->total_gross_profit ?? 0, 2),

                // Ganancia Neta (Lo que le quedó a la empresa "Admin")
                'total_admin_profit' => round($stats->total_net_profit ?? 0, 2),

                // Volumen total (Suma de lo enviado + recibido)
                'total_moved'        => round($stats->total_volume_moved ?? 0, 2),

                'transaction_count'  => $stats->total_transactions,
            ];
        }

        return [
            'reports' => $reports,
            'period'  => [
                'start_date' => $startDate->toDateString(),
                'end_date'   => $endDate->toDateString(),
            ],
        ];
    }

    // Métodos públicos para las rutas (Asegúrate que coinciden con api.php)

    public function getClientReport(Request $request)
    {
        return response()->json($this->getEntityReport('client', $request));
    }

    public function getProviderReport(Request $request)
    {
        return response()->json($this->getEntityReport('provider', $request));
    }

    public function getPlatformReport(Request $request)
    {
        return response()->json($this->getEntityReport('platform', $request));
    }

    public function getBrokerReport(Request $request)
    {
        return response()->json($this->getEntityReport('broker', $request));
    }
}
