<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investor;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalculateInvestorInterests extends Command
{
    // Firma con las opciones para pruebas manuales
    protected $signature = 'investors:calculate-interest {--force : Forzar el pago ignorando fechas} {--id= : ID específico para probar}';
    
    protected $description = 'Calcula interés compuesto y lo SUMA a la deuda existente.';

    public function handle()
    {
        $force = $this->option('force');
        $specificId = $this->option('id');

        $today = Carbon::now();
        $day = $today->day;
        $isLastDay = $today->copy()->endOfMonth()->isToday();

        $this->info("📅 Fecha: {$today->toDateString()}");
        if ($force) $this->warn("🔥 MODO FORZADO ACTIVADO");

        // QUERY DE INVERSIONISTAS
        $query = Investor::where('is_active', true);

        if ($specificId) {
            $query->where('id', $specificId);
        } elseif (!$force) {
            $query->where(function ($q) use ($day, $isLastDay) {
                $q->where('payout_day', $day);
                if ($isLastDay) $q->orWhere('payout_day', '>', $day);
            });
        }

        $investors = $query->get();

        if ($investors->isEmpty()) {
            $this->info("✅ No hay inversionistas pendientes.");
            return;
        }

        $count = 0;

        foreach ($investors as $investor) {

            // Validación de fecha (si no es forzado)
            if (!$force) {
                $lastDate = $investor->last_interest_date ? Carbon::parse($investor->last_interest_date) : null;
                if ($lastDate && $lastDate->month == $today->month && $lastDate->year == $today->year) {
                    $this->warn("⚠️ {$investor->name} ya cobró este mes. Saltando.");
                    continue;
                }
            }

            DB::transaction(function () use ($investor) {
                
                // 1. Agrupar deudas por moneda
                $entriesByCurrency = $investor->ledgerEntries()
                    ->where('type', 'payable')
                    ->where('status', '!=', 'paid')
                    ->get()
                    ->groupBy('currency_code');

                $generatedAny = false;

                foreach ($entriesByCurrency as $currencyCode => $entries) {
                    
                    // Calcular Capital Base (Suma de todo lo que le debemos en esa moneda)
                    $capitalBase = $entries->sum(function ($entry) {
                        return $entry->original_amount - $entry->paid_amount;
                    });

                    if ($capitalBase <= 0) continue;

                    // Calcular Interés
                    $interestAmount = $capitalBase * ($investor->interest_rate / 100);

                    if ($interestAmount <= 0) continue;

                    // 🔥 CAMBIO CLAVE: BUSCAR DEUDA EXISTENTE PARA UNIFICAR 🔥
                    // Buscamos la primera deuda activa en esa moneda para sumarle el interés ahí mismo
                    $existingDebt = $investor->ledgerEntries()
                        ->where('type', 'payable')
                        ->where('currency_code', $currencyCode)
                        ->where('status', '!=', 'paid')
                        ->orderBy('id', 'asc') // Usamos la más antigua como la principal
                        ->first();

                    if ($existingDebt) {
                        // A) ACTUALIZAR EXISTENTE (Engordar la deuda)
                        $existingDebt->amount += $interestAmount;
                        $existingDebt->original_amount += $interestAmount;
                        // Opcional: Actualizar descripción para que se sepa que hubo un movimiento
                        // $existingDebt->description = "Capital Base + Rendimientos"; 
                        $existingDebt->save();

                        $this->info("📈 {$investor->name}: Deuda actualizada +{$interestAmount} {$currencyCode} (Nuevo Total: {$existingDebt->amount})");

                    } else {
                        // B) CREAR NUEVA (Solo si no tenía deuda previa, caso raro)
                        $investor->ledgerEntries()->create([
                            'tenant_id'       => $investor->tenant_id ?? 1,
                            'description'     => "Capital Inicial + Rendimiento (" . now()->format('Y-m') . ")",
                            'amount'          => $interestAmount,
                            'original_amount' => $interestAmount,
                            'currency_code'   => $currencyCode ?: 'USD',
                            'paid_amount'     => 0,
                            'type'            => 'payable',
                            'status'          => 'pending',
                            'due_date'        => now(),
                        ]);
                        $this->info("🆕 {$investor->name}: Nueva Deuda creada +{$interestAmount}");
                    }

                    $generatedAny = true;
                }

                if ($generatedAny) {
                    $investor->forceFill(['last_interest_date' => Carbon::today()])->save();
                }
            });

            $count++;
        }

        $this->info("🏁 Proceso completado. {$count} inversores procesados.");
    }
}