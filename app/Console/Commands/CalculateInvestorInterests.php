<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investor;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalculateInvestorInterests extends Command
{
    protected $signature = 'investors:calculate-interest';
    protected $description = 'Calcula interés compuesto (Maneja fin de mes inteligente)';

    public function handle()
    {
        $today = Carbon::now();
        $day = $today->day;
        $isLastDay = $today->copy()->endOfMonth()->isToday();

        $this->info("📅 Fecha: {$today->toDateString()} (Día {$day})");
        if ($isLastDay) $this->info("🚨 Es el último día del mes. Verificando fechas desbordadas...");

        // LÓGICA INTELIGENTE:
        // 1. Si NO es fin de mes, busca solo el día exacto.
        // 2. Si ES fin de mes, busca el día exacto Y todos los mayores (ej: si estamos a 28 feb, busca 28, 29, 30, 31).
        
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
            
            // VALIDACIÓN DOBLE: Verificar que no haya cobrado YA en este mes y año específico
            // Esto evita que si corre el 28 y luego el 29 (bisiesto), cobre doble.
            $lastDate = $investor->last_interest_date ? Carbon::parse($investor->last_interest_date) : null;
            
            if ($lastDate && $lastDate->month == $today->month && $lastDate->year == $today->year) {
                $this->warn("⚠️ {$investor->name} ya cobró este mes ({$lastDate->toDateString()}). Saltando.");
                continue;
            }

            DB::transaction(function () use ($investor) {
                $currentBalance = $investor->available_balance;

                if ($currentBalance <= 0) return;

                $interestAmount = $currentBalance * ($investor->interest_rate / 100);

                if ($interestAmount <= 0) return;

                // --- FUSIÓN DE SALDO (Igual que antes) ---
                $latestEntry = $investor->ledgerEntries()
                    ->where('type', 'payable')
                    ->where('status', '!=', 'paid')
                    ->latest()
                    ->first();

                if ($latestEntry) {
                    $latestEntry->amount += $interestAmount;
                    $latestEntry->original_amount += $interestAmount;
                    
                    if (!str_contains($latestEntry->description, '+ Rendimiento')) {
                        $latestEntry->description .= " + Rendimiento";
                    }
                    $latestEntry->save();
                    
                    $this->info("💰 {$investor->name} (Día {$investor->payout_day}): +$ {$interestAmount}");
                } else {
                    $investor->ledgerEntries()->create([
                        'tenant_id'       => $investor->tenant_id ?? 1,
                        'description'     => "Capital Inicial + Rendimiento",
                        'amount'          => $currentBalance + $interestAmount,
                        'original_amount' => $currentBalance + $interestAmount,
                        'paid_amount'     => 0,
                        'type'            => 'payable',
                        'status'          => 'pending',
                        'due_date'        => now(),
                    ]);
                    $this->info("🆕 {$investor->name}: Se creó registro nuevo.");
                }

                // Guardamos la fecha de hoy como "Último cobro"
                $investor->forceFill(['last_interest_date' => Carbon::today()])->save();
            });

            $count++;
        }

        $this->info("🏁 Proceso completado. {$count} actualizados.");
    }
}