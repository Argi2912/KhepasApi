<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Broker;
use App\Models\CurrencyExchange;
use App\Models\DollarPurchase; // Si usas compras
use App\Models\InternalTransaction;
use App\Models\LedgerEntry;
use App\Models\Provider;
use App\Models\User;
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
     * 1. Crea el Intercambio
     * 2. Mueve saldos de cuentas
     * 3. Genera DEUDAS AUTOMÁTICAS (Ledger) para Broker y Proveedor
     */
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // 1. Generamos el número ANTES para usarlo en la descripción del historial
            $exchangeNumber = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');

            // A. Validar Saldos y Bloquear Cuentas
            $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $amountSent = (float) $data['amount_sent'];
            $amountReceived = (float) $data['amount_received'];
            
            if ($fromAccount->balance < $amountSent) {
                throw new Exception("Saldo insuficiente en {$fromAccount->name} para enviar {$amountSent} {$fromAccount->currency_code}.");
            }
            
            // B. Mover Saldos
            $fromAccount->decrement('balance', $amountSent);
            $toAccount->increment('balance', $amountReceived);

            // 🔥 C. NUEVO: REGISTRO EN HISTORIAL DE CAJA 🔥
            
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
                'tenant_id' => Auth::user()->tenant_id,
                'number' => $exchangeNumber, // Usamos el número generado arriba
                'client_id' => $data['client_id'] ?? null,
                'broker_id' => $data['broker_id'] ?? null,
                'provider_id' => $data['provider_id'] ?? null,
                'admin_user_id' => $data['admin_user_id'],
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount_sent' => $amountSent,
                'amount_received' => $amountReceived,
                'exchange_rate' => $data['exchange_rate'],
                'buy_rate'      => $data['buy_rate'] ?? null,
                'commission_total_amount' => $data['commission_total_amount'] ?? 0,
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount' => $data['commission_admin_amount'] ?? 0,
                'trader_info' => $data['trader_info'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => $data['status'] ?? 'completed',
            ]);

            // E. Generar Asientos Contables (Ledger Entries) - Lógica Original Intacta

            // E.1. Comisión a Pagar al Broker (CxP)
            $brokerCommAmount = (float) ($data['commission_admin_amount'] ?? 0); 
            $broker = isset($data['broker_id']) ? Broker::find($data['broker_id']) : null;
            if ($brokerCommAmount > 0 && $broker) {
                $exchange->ledgerEntries()->create([
                    'tenant_id' => $exchange->tenant_id,
                    'description' => "Comisión Corredor {$broker->user->name} (Op. #{$exchange->number})",
                    'amount' => $brokerCommAmount,
                    'type' => 'payable', 
                    'status' => 'pending', 
                    'entity_id' => $broker->id,
                    'entity_type' => Broker::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id' => $exchange->id,
                ]);
            }

            // E.2. Comisión Ganada por la Empresa (CxC)
            $companyCommAmount = (float) ($data['commission_total_amount'] ?? 0);
            if ($companyCommAmount > 0 && !empty($exchange->client_id)) {
                $client = \App\Models\Client::find($exchange->client_id);
                $isDeferred = $data['is_commission_deferred'] ?? false;
                $status = $isDeferred ? 'pending' : 'paid';

                if ($client) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id' => $exchange->tenant_id,
                        'description' => "Comisión de Casa - Sol. #{$exchange->number}",
                        'amount' => $companyCommAmount,
                        'type' => 'receivable', 
                        'status' => $status,
                        'entity_id' => $client->id,
                        'entity_type' => \App\Models\Client::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $exchange->id,
                    ]);
                }
            }
            
            // E.3. Comisión a Pagar al Proveedor (CxP)
            $providerCommAmount = (float) ($data['commission_provider_amount'] ?? 0); 
            $provider = isset($data['provider_id']) ? Provider::find($data['provider_id']) : null;
            if ($providerCommAmount > 0 && $provider) {
                $exchange->ledgerEntries()->create([
                    'tenant_id' => $exchange->tenant_id,
                    'description' => "Comisión Proveedor {$provider->name} (Op. #{$exchange->number})",
                    'amount' => $providerCommAmount,
                    'type' => 'payable', 
                    'status' => 'pending',
                    'entity_id' => $provider->id,
                    'entity_type' => Provider::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id' => $exchange->id,
                ]);
            }

            return $exchange->load('client', 'fromAccount', 'toAccount');
        });
    }
    
    /**
     * NUEVO: Paga una deuda del Ledger creando una Transacción Interna.
     * Esto cierra el ciclo: LedgerEntry (Pendiente) -> InternalTransaction (Dinero Real) -> LedgerEntry (Pagado)
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
                // --- ESCENARIO A: CUENTA POR PAGAR (Sale dinero) ---
                // Ejemplo: Le pagamos la comisión al Broker.
                
                if ($account->balance < $entry->amount) {
                    throw new Exception("Saldo insuficiente en {$account->name} para pagar esta deuda.");
                }
                
                $account->decrement('balance', $entry->amount);
                
                $txType = 'expense';
                $category = 'Pago de Comisiones';
                $descPrefix = "Pago de deuda";

            } else {
                // --- ESCENARIO B: CUENTA POR COBRAR (Entra dinero) ---
                // Ejemplo: Un cliente nos paga un préstamo o saldo pendiente.
                
                // Aquí NO validamos saldo insuficiente, porque estamos RECIBIENDO dinero.
                $account->increment('balance', $entry->amount);
                
                $txType = 'income';
                $category = 'Cobro de Deuda';
                $descPrefix = "Cobro de crédito";
            }

            // 3. Crear registro en Caja (InternalTransaction)
            // Esto deja huella en el historial de la cuenta bancaria/caja
            $internalTx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id,
                'user_id' => Auth::id(),
                'account_id' => $account->id,
                'type' => $txType,        // 'expense' o 'income'
                'category' => $category,  // Categoría automática
                'amount' => $entry->amount,
                'description' => "{$descPrefix} #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
                'dueño'       => $data['dueño'] ?? null,
                'person_name' => $data['person_name'] ?? null,
            ]);

            // 4. Marcar el Asiento como COMPLETADO ('paid')
            $entry->update(['status' => 'paid']);

            return $internalTx;
        });
    }
    
    /**
     * Mantiene la lógica original para gastos manuales directos (sin pasar por deuda)
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
    
    // Agrega aquí createDollarPurchase si lo necesitas, siguiendo la misma lógica que createCurrencyExchange
}