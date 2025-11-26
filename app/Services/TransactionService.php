<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Broker;
use App\Models\CurrencyExchange;
use App\Models\InternalTransaction;
use App\Models\LedgerEntry;
use App\Models\Provider;
use App\Models\Client;
// Asegúrate de importar tu modelo de Plataforma si existe, ej: use App\Models\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class TransactionService
{
    /**
     * Genera un número secuencial (Ej: CE-00001)
     */
    private function generateSequentialNumber(string $modelClass, string $prefix): string
    {
        $latest = $modelClass::latest('id')->first();
        $nextId = $latest ? $latest->id + 1 : 1;
        return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * 1. Crea el Intercambio / Cambio de Divisa
     * 2. Mueve saldos de cuentas (Dinero Real)
     * 3. Genera DEUDAS AUTOMÁTICAS (Ledger) para Broker, Proveedor y Plataforma
     */
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // 1. Generamos el número
            $exchangeNumber = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');

            // A. Validar Saldos y Bloquear Cuentas
            $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $amountSent = (float) $data['amount_sent'];
            $amountReceived = (float) $data['amount_received'];
            
            if ($fromAccount->balance < $amountSent) {
                throw new Exception("Saldo insuficiente en {$fromAccount->name} para enviar {$amountSent} {$fromAccount->currency_code}.");
            }
            
            // B. Mover Saldos (Dinero Real)
            $fromAccount->decrement('balance', $amountSent);
            $toAccount->increment('balance', $amountReceived);

            // C. Registro en Historial de Caja (InternalTransaction)
            
            // C.1. Registro de SALIDA (Gasto) en cuenta origen
            InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => $data['admin_user_id'],
                'account_id' => $fromAccount->id,
                'type' => 'expense',
                'category' => 'Intercambio Enviado',
                'amount' => $amountSent,
                'description' => "Salida Intercambio {$exchangeNumber} hacia {$toAccount->name}",
                'transaction_date' => now(),
                'dueño' => $data['account_owner'] ?? null,
                'person_name' => $data['person_name'] ?? null,
            ]);

            // C.2. Registro de ENTRADA (Ingreso) en cuenta destino
            InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => $data['admin_user_id'],
                'account_id' => $toAccount->id,
                'type' => 'income',
                'category' => 'Intercambio Recibido',
                'amount' => $amountReceived,
                'description' => "Entrada Intercambio {$exchangeNumber} desde {$fromAccount->name}",
                'transaction_date' => now(),
                'dueño' => $data['account_owner'] ?? null,
                'person_name' => $data['person_name'] ?? null,
            ]);

            // D. Crear el registro principal de Intercambio
            $exchange = CurrencyExchange::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1, // Fallback si null
                'number' => $exchangeNumber,
                'client_id' => $data['client_id'] ?? null,
                'broker_id' => $data['broker_id'] ?? null,
                'provider_id' => $data['provider_id'] ?? null,
                'platform_id' => $data['platform_id'] ?? null, // Guardamos la plataforma
                'admin_user_id' => $data['admin_user_id'],
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount_sent' => $amountSent,
                'amount_received' => $amountReceived,
                'exchange_rate' => $data['exchange_rate'],
                'buy_rate'      => $data['buy_rate'] ?? null,
                
                // Montos de comisiones para referencia rápida
                'commission_total_amount' => $data['commission_charged_amount'] ?? 0, // Ingreso Bruto
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount' => $data['commission_admin_amount'] ?? 0,
                'commission_broker_amount' => $data['commission_broker_amount'] ?? 0,
                
                'trader_info' => $data['trader_info'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => $data['status'] ?? 'completed',
            ]);

            // =================================================================
            // E. GENERAR ASIENTOS (CUENTAS POR PAGAR / COBRAR) - AUTOMATIZACIÓN
            // =================================================================

            // E.1. Comisión a Pagar al PROVEEDOR (CxP)
            $providerCommAmount = (float) ($data['commission_provider_amount'] ?? 0); 
            $providerId = $data['provider_id'] ?? null;
            
            if ($providerCommAmount > 0 && $providerId) {
                $provider = Provider::find($providerId);
                if ($provider) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id' => $exchange->tenant_id,
                        'description' => "Comisión Proveedor {$provider->name} (Op. #{$exchange->number})",
                        'amount' => $providerCommAmount,
                        'type' => 'payable', // Significa que debemos dinero
                        'status' => 'pending',
                        'entity_id' => $provider->id,
                        'entity_type' => Provider::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $exchange->id,
                    ]);
                }
            }

            // E.2. Comisión a Pagar al CORREDOR/BROKER (CxP)
            // 🚨 CORREGIDO: Ahora usa commission_broker_amount correctamente
            $brokerCommAmount = (float) ($data['commission_broker_amount'] ?? 0); 
            $brokerId = $data['broker_id'] ?? null;
            
            if ($brokerCommAmount > 0 && $brokerId) {
                $broker = Broker::find($brokerId);
                if ($broker) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id' => $exchange->tenant_id,
                        // Usamos $broker->name o $broker->user->name según tu modelo
                        'description' => "Comisión Corredor (Op. #{$exchange->number})", 
                        'amount' => $brokerCommAmount,
                        'type' => 'payable', // Deuda
                        'status' => 'pending', 
                        'entity_id' => $broker->id,
                        'entity_type' => Broker::class, 
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $exchange->id,
                    ]);
                }
            }

            // E.3. Costo de PLATAFORMA (CxP)
            // 🚨 NUEVO BLOQUE: Registra la deuda a la plataforma
            $platformCommAmount = (float) ($data['commission_admin_amount'] ?? 0);
            $platformId = $data['platform_id'] ?? null;

            if ($platformCommAmount > 0 && $platformId) {
                // Aquí asumimos que tienes un modelo Platform, o usas una entidad genérica
                // Si 'platform_id' es una entidad real en BD:
                $exchange->ledgerEntries()->create([
                    'tenant_id' => $exchange->tenant_id,
                    'description' => "Costo Plataforma/Admin (Op. #{$exchange->number})",
                    'amount' => $platformCommAmount,
                    'type' => 'payable', // Deuda
                    'status' => 'pending',
                    'entity_id' => $platformId,
                    'entity_type' => 'App\Models\Platform', // Ajusta según tu modelo real
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id' => $exchange->id,
                ]);
            }

            // E.4. Comisión Ganada por la Empresa (CxC - Opcional)
            // Registra el ingreso de la comisión cobrada al cliente
            $companyCommAmount = (float) ($data['commission_charged_amount'] ?? 0);
            
            if ($companyCommAmount > 0 && !empty($exchange->client_id)) {
                $client = Client::find($exchange->client_id);
                // Si la comisión ya se cobró en el monto total, se marca 'paid', si es a crédito, 'pending'
                $isDeferred = $data['is_commission_deferred'] ?? false;
                $status = $isDeferred ? 'pending' : 'paid';

                if ($client) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id' => $exchange->tenant_id,
                        'description' => "Comisión de Casa - Op. #{$exchange->number}",
                        'amount' => $companyCommAmount,
                        'type' => 'receivable', // Dinero a favor
                        'status' => $status,
                        'entity_id' => $client->id,
                        'entity_type' => Client::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $exchange->id,
                    ]);
                }
            }

            return $exchange->load('client', 'fromAccount', 'toAccount');
        });
    }
    
    /**
     * Paga una deuda del Ledger creando una Transacción Interna.
     */
    public function processDebtPayment(LedgerEntry $entry, int $accountId)
    {
        return DB::transaction(function () use ($entry, $accountId) {
            // 1. Validaciones básicas
            if ($entry->status === 'paid') {
                throw new Exception("Este asiento ya fue procesado anteriormente.");
            }

            $account = Account::lockForUpdate()->findOrFail($accountId);

            // 2. LÓGICA BIFURCADA (PAYABLE vs RECEIVABLE)
            if ($entry->type === 'payable') {
                // SALIDA DE DINERO (Pago de Deuda)
                if ($account->balance < $entry->amount) {
                    throw new Exception("Saldo insuficiente en {$account->name} para pagar esta deuda.");
                }
                
                $account->decrement('balance', $entry->amount);
                
                $txType = 'expense';
                $category = 'Pago de Comisiones';
                $descPrefix = "Pago de deuda";

            } else {
                // ENTRADA DE DINERO (Cobro de Crédito)
                $account->increment('balance', $entry->amount);
                
                $txType = 'income';
                $category = 'Cobro de Deuda';
                $descPrefix = "Cobro de crédito";
            }

            // 3. Crear registro en Caja (InternalTransaction)
            $internalTx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id,
                'user_id' => Auth::id(),
                'account_id' => $account->id,
                'type' => $txType,
                'category' => $category,
                'amount' => $entry->amount,
                'description' => "{$descPrefix} #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
            ]);

            // 4. Marcar el Asiento como COMPLETADO ('paid')
            $entry->update(['status' => 'paid']);

            return $internalTx;
        });
    }
    
    /**
     * Crea transacciones manuales directas
     */
    public function createInternalTransaction(array $data)
    {
         return DB::transaction(function () use ($data) {
            $account = Account::lockForUpdate()->findOrFail($data['account_id']);
            
            if ($data['type'] === 'expense') {
                if ($account->balance < $data['amount']) {
                    throw new Exception("Saldo insuficiente en {$account->name}");
                }
                $account->decrement('balance', $data['amount']);
            } else {
                $account->increment('balance', $data['amount']);
            }

            return InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => $data['user_id'],
                'account_id' => $data['account_id'],
                'type' => $data['type'],
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now(),
                'dueño'       => $data['dueño'] ?? null,
                'person_name' => $data['person_name'] ?? null,
            ]);
        });
    }
}