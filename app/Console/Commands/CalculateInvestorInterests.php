<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investor;
use App\Models\LedgerEntry; // Asegúrate de importar esto si lo usas explícitamente, aunque usamos la relación
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalculateInvestorInterests extends Command
{
    protected $signature = 'investors:calculate-interest';
    protected $description = 'Calcula interés compuesto creando nuevos registros de deuda por moneda.';

    public function handle()
    {
        $today = Carbon::now();
        $day = $today->day;
        $isLastDay = $today->copy()->endOfMonth()->isToday();

        $this->info("📅 Fecha: {$today->toDateString()} (Día {$day})");
        if ($isLastDay) $this->info("🚨 Es el último día del mes. Verificando fechas desbordadas...");

        // Seleccionamos inversores activos que cobran hoy (o días posteriores si es fin de mes)
        $investors = Investor::where('is_active', true)
            ->where(function ($query) use ($day, $isLastDay) {
                $query->where('payout_day', $day);
                if ($isLastDay) {
                    $query->orWhere('payout_day', '>', $day);
                }
            })
            ->get();

        if ($investors->isEmpty()) {
            $this->info("✅ No hay pagos pendientes para hoy.");
            return;
        }

        $count = 0;

        foreach ($investors as $investor) {

            // VALIDACIÓN DOBLE: Verificar que no haya cobrado YA en este mes
            $lastDate = $investor->last_interest_date ? Carbon::parse($investor->last_interest_date) : null;

            if ($lastDate && $lastDate->month == $today->month && $lastDate->year == $today->year) {
                $this->warn("⚠️ {$investor->name} ya cobró este mes ({$lastDate->toDateString()}). Saltando.");
                continue;
            }

            DB::transaction(function () use ($investor) {
                // 1. Agrupar deudas pendientes por MONEDA (Crítico para sistema multi-divisa)
                // Obtenemos todos los asientos 'payable' (que debemos al inversor) y que no estén pagados.
                $entriesByCurrency = $investor->ledgerEntries()
                    ->where('type', 'payable')
                    ->where('status', '!=', 'paid')
                    ->get()
                    ->groupBy('currency_code');

                $generatedAny = false;

                foreach ($entriesByCurrency as $currencyCode => $entries) {
                    // 2. Calcular capital base (Suma de montos pendientes en esta moneda)
                    // (Original - Pagado) nos da el saldo real vivo sobre el cual calcular interés.
                    $capitalBase = $entries->sum(function ($entry) {
                        return $entry->original_amount - $entry->paid_amount;
                    });

                    if ($capitalBase <= 0) continue;

                    // 3. Calcular Monto del Interés
                    $interestAmount = $capitalBase * ($investor->interest_rate / 100);

                    if ($interestAmount <= 0) continue;

                    // 4. CREAR NUEVO ASIENTO (Separación limpia de Capital vs Interés)
                    $investor->ledgerEntries()->create([
                        'tenant_id'       => $investor->tenant_id ?? 1,
                        'description'     => "Rendimiento Mensual (" . now()->format('Y-m') . ")",
                        'amount'          => $interestAmount,
                        'original_amount' => $interestAmount,
                        'currency_code'   => $currencyCode ?: 'USD', // Fallback por seguridad
                        'paid_amount'     => 0,
                        'type'            => 'payable',
                        'status'          => 'pending',
                        'due_date'        => now(),
                        // 'entity_type' y 'entity_id' se llenan solos por la relación morphMany
                    ]);

                    $this->info("💰 {$investor->name}: +{$interestAmount} {$currencyCode} (Base: {$capitalBase})");
                    $generatedAny = true;
                }

                // Actualizamos la fecha de último cobro siempre que procesemos al inversor
                // para evitar re-procesos el mismo día.
                $investor->forceFill(['last_interest_date' => Carbon::today()])->save();
            });

            $count++;
        }

        $this->info("🏁 Proceso completado. {$count} inversores procesados.");
    }
}
