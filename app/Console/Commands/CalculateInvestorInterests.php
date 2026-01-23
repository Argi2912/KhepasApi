<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investor;
use App\Models\LedgerEntry;
use App\Models\InternalTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalculateInvestorInterests extends Command
{
    protected $signature = 'investors:calculate-interest {--force : Forzar el pago aunque no sea la fecha o ya esté pagado} {--id= : ID específico}';
    protected $description = 'Calcula intereses y crea una fila NUEVA de deuda por el rendimiento.';

    public function handle()
    {
        $this->info("🤖 INICIANDO ROBOT DE INTERESES...");

        $force = $this->option('force'); // Detectar si usamos --force
        $specificId = $this->option('id');
        $today = Carbon::now();

        // 1. Buscar Inversionistas
        $query = Investor::where('is_active', true);
        if ($specificId) $query->where('id', $specificId);
        $investors = $query->get();
        
        if ($investors->isEmpty()) {
            $this->error("❌ No encontré inversionistas activos.");
            return;
        }

        $this->info("✅ Encontré " . $investors->count() . " inversionistas activos.");

        foreach ($investors as $investor) {
            $this->info("------------------------------------------------");
            $this->info("🔍 Analizando a: {$investor->name} (ID: {$investor->id})");

            // =================================================================
            // 🛡️ PROTECCIONES (EVITAR COBRO DOBLE O FECHA INCORRECTA)
            // =================================================================
            
            if (!$force) {
                // 1. Chequeo de duplicado: ¿Ya se corrió hoy?
                if ($investor->last_interest_date && Carbon::parse($investor->last_interest_date)->isToday()) {
                    $this->warn("   ⏸️ Este inversionista YA cobró hoy ({$today->toDateString()}). Saltando para no duplicar.");
                    continue; 
                }

                // 2. Chequeo de fecha de corte: ¿Hoy es su día de pago?
                // (Si hoy es 22 y su payout_day es 22, procede. Si no, salta).
                if ($investor->payout_day && $today->day != $investor->payout_day) {
                    $this->comment("   🗓️ Hoy es día {$today->day}, pero su día de corte es el {$investor->payout_day}. Esperando...");
                    continue;
                }
            } else {
                $this->warn("   ⚠️ MODO FUERZA ACTIVO: Ignorando validaciones de fecha.");
            }
            // =================================================================

            // 2. Buscar Deudas
            $entries = $investor->ledgerEntries()
                ->where('type', 'payable')
                ->whereIn('status', ['pending', 'partial'])
                ->get();

            if ($entries->isEmpty()) {
                $this->warn("   ⚠️ No tiene deudas pendientes activas.");
                continue;
            }

            // Agrupar por moneda
            $entriesByCurrency = $entries->groupBy('currency_code');

            foreach ($entriesByCurrency as $currencyCode => $groupEntries) {
                
                // 3. Calcular Capital Base
                $capitalBase = $groupEntries->sum(function ($entry) {
                    return $entry->original_amount - $entry->paid_amount;
                });

                $this->info("   💰 Moneda: $currencyCode | Capital Base Calculado: $capitalBase");

                if ($capitalBase <= 0) {
                    $this->warn("   ⚠️ El capital base es 0 o negativo. Saltando.");
                    continue;
                }

                // 4. Obtener Porcentaje (interest_rate)
                $percentage = floatval($investor->interest_rate);

                if ($percentage <= 0) {
                    $this->error("   ❌ ERROR: El porcentaje (interest_rate) es 0%.");
                    continue; 
                }

                // 5. Calcular Interés
                $interestAmount = $capitalBase * ($percentage / 100);
                $interestAmount = round($interestAmount, 2);

                $this->info("   🧮 Cálculo: $capitalBase * $percentage% = $interestAmount");

                if ($interestAmount <= 0) {
                    $this->warn("   ⚠️ El interés calculado es 0. Saltando.");
                    continue;
                }

                // 6. CREAR LA NUEVA FILA
                try {
                    DB::transaction(function () use ($investor, $interestAmount, $capitalBase, $currencyCode, $percentage) {
                        $investor->ledgerEntries()->create([
                            'tenant_id'       => $investor->tenant_id ?? 1,
                            'entity_type'     => get_class($investor),
                            'entity_id'       => $investor->id,
                            'type'            => 'payable',
                            'amount'          => $interestAmount, 
                            'original_amount' => $interestAmount,
                            'paid_amount'     => 0,
                            'status'          => 'pending',
                            'currency_code'   => $currencyCode ?: 'USD',
                            'description'     => "Interés Generado ({$percentage}%)",
                            'due_date'        => now(),
                            'created_at'      => now(),
                        ]);
                        
                        // Actualizar saldo visual
                        $investor->increment('available_balance', $interestAmount);
                        
                        // Actualizar la fecha de último cobro a HOY para activar el bloqueo de duplicados
                        $investor->forceFill(['last_interest_date' => Carbon::today()])->save();
                    });

                    $this->info("   ✅ ¡ÉXITO! Fila de interés creada por $interestAmount.");

                } catch (\Exception $e) {
                    $this->error("   ❌ ERROR AL GUARDAR EN BD: " . $e->getMessage());
                }
            }
        }
        
        $this->info("🤖 PROCESO TERMINADO.");
    }
}