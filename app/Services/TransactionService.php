<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Broker;
use App\Models\Client;
use App\Models\CurrencyExchange;
use App\Models\InternalTransaction;
use App\Models\Investor;
use App\Models\LedgerEntry;
use App\Models\Platform;
use App\Models\Provider;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    private function generateSequentialNumber(string $modelClass, string $prefix): string
    {
        $latest = $modelClass::latest('id')->first();
        $nextId = $latest ? $latest->id + 1 : 1;
        return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Crea Intercambio + Movimiento de Saldos + Asientos Contables (Incluyendo Inversionistas)
     */
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {

            // 1. Generamos el número secuencial
            $exchangeNumber = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');
            $tenantId       = Auth::user()->tenant_id ?? 1;

            // Variables iniciales
            $fromAccount    = null;
            $fromAccountId  = null;
            $capitalType    = $data['capital_type'] ?? 'own';
            $amountSent     = (float) $data['amount_sent'];
            $amountReceived = (float) $data['amount_received'];

            // -------------------------------------------------------------
            // A. LÓGICA DE CUENTA ORIGEN (SOLO SI ES CAPITAL PROPIO)
            // -------------------------------------------------------------
            if ($capitalType === 'own') {
                if (empty($data['from_account_id'])) {
                    throw new Exception("Se requiere una cuenta de origen para capital propio.");
                }

                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);

                // Validar saldo
                if ($fromAccount->balance < $amountSent) {
                    throw new Exception("Saldo insuficiente en {$fromAccount->name} para enviar {$amountSent} {$fromAccount->currency_code}.");
                }

                // Descontar saldo
                $fromAccount->decrement('balance', $amountSent);
                $fromAccountId = $fromAccount->id;
            }

            // -------------------------------------------------------------
            // B. LÓGICA DE CUENTA DESTINO (SIEMPRE ENTRA DINERO)
            // -------------------------------------------------------------
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $toAccount->increment('balance', $amountReceived);

            // -------------------------------------------------------------
            // C. DETERMINAR DIVISAS
            // -------------------------------------------------------------
            $currencySent     = $capitalType === 'own' && $fromAccount ? $fromAccount->currency_code : null;
            $currencyReceived = $toAccount->currency_code;

            // -------------------------------------------------------------
            // D. CREAR EL REGISTRO DE INTERCAMBIO
            // -------------------------------------------------------------
            $exchange = CurrencyExchange::create([
                'tenant_id'                  => $tenantId,
                'number'                     => $exchangeNumber,
                'client_id'                  => $data['client_id'],
                'admin_user_id'              => $data['admin_user_id'],
                'broker_id'                  => $data['broker_id'] ?? null,
                'provider_id'                => $data['provider_id'] ?? null,
                'from_account_id'            => $fromAccountId,
                'to_account_id'              => $data['to_account_id'],
                'amount_sent'                => $amountSent,
                'amount_received'            => $amountReceived,
                'exchange_rate'              => $data['exchange_rate'] ?? null,
                'buy_rate'                   => $data['buy_rate'] ?? null,
                'commission_total_amount'    => $data['commission_total_amount'] ?? 0,
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount'    => $data['commission_admin_amount'] ?? 0,
                'commission_broker_amount'   => $data['commission_broker_amount'] ?? 0,
                'trader_info'                => $data['trader_info'] ?? null,
                'reference_id'               => $data['reference_id'] ?? null,
                'status'                     => $data['status'],
                'capital_type'               => $capitalType,
                'investor_id'                => $data['investor_id'] ?? null,
                'investor_profit_pct'        => $data['investor_profit_pct'] ?? 0,
                'investor_profit_amount'     => $data['investor_profit_amount'] ?? 0,

                // === NUEVO: DIVISAS ===
                'currency_sent'              => $currencySent,
                'currency_received'          => $currencyReceived,
            ]);

            // -------------------------------------------------------------
            // E. REGISTROS DE CAJA (InternalTransaction)
            // -------------------------------------------------------------

            // Salida (solo si capital propio)
            if ($capitalType === 'own' && $fromAccount) {
                InternalTransaction::create([
                    'tenant_id'        => $tenantId,
                    'user_id'          => $data['admin_user_id'],
                    'account_id'       => $fromAccount->id,
                    'type'             => 'expense',
                    'category'         => 'Intercambio Enviado',
                    'amount'           => $amountSent,
                    'currency_code'    => $currencySent,                    // ← NUEVO
                    'description'      => "Salida Intercambio {$exchangeNumber}",
                    'transaction_date' => now(),
                ]);
            }

            // Entrada (siempre)
            InternalTransaction::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $data['admin_user_id'],
                'account_id'       => $toAccount->id,
                'type'             => 'income',
                'category'         => 'Intercambio Recibido',
                'amount'           => $amountReceived,
                'currency_code'    => $currencyReceived,                   // ← NUEVO
                'description'      => "Entrada Intercambio {$exchangeNumber}",
                'transaction_date' => now(),
            ]);

            // -------------------------------------------------------------
            // F. ASIENTOS CONTABLES (Ledger Entries)
            // -------------------------------------------------------------

            // Comisión al Proveedor (si aplica)
            if (!empty($data['commission_provider_amount']) && !empty($data['provider_id'])) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'       => $tenantId,
                    'description'     => "Comisión proveedor - Intercambio {$exchangeNumber}",
                    'amount'          => $data['commission_provider_amount'],
                    'original_amount' => $data['commission_provider_amount'],
                    'paid_amount'     => 0,
                    'currency_type'   => $currencyReceived,                // ← Comisión en moneda recibida
                    'type'            => 'payable',
                    'status'          => 'pending',
                    'entity_type'     => Provider::class,
                    'entity_id'       => $data['provider_id'],
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'  => $exchange->id,
                    'due_date'        => now(),
                ]);
            }

            // Comisión al Broker (si aplica)
            if (!empty($data['commission_broker_amount']) && !empty($data['broker_id'])) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'       => $tenantId,
                    'description'     => "Comisión corredor - Intercambio {$exchangeNumber}",
                    'amount'          => $data['commission_broker_amount'],
                    'original_amount' => $data['commission_broker_amount'],
                    'paid_amount'     => 0,
                    'currency_type'   => $currencyReceived,
                    'type'            => 'payable',
                    'status'          => 'pending',
                    'entity_type'     => Broker::class,
                    'entity_id'       => $data['broker_id'],
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'  => $exchange->id,
                    'due_date'        => now(),
                ]);
            }

            // Ganancia de la casa / admin
            if (!empty($data['commission_admin_amount'])) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'       => $tenantId,
                    'description'     => "Ganancia casa - Intercambio {$exchangeNumber}",
                    'amount'          => $data['commission_admin_amount'],
                    'original_amount' => $data['commission_admin_amount'],
                    'paid_amount'     => 0,
                    'currency_type'   => $currencyReceived,
                    'type'            => 'receivable',  // La casa se "debe" a sí misma (ganancia)
                    'status'          => 'paid',       // Ya está "cobrado" al recibir
                    'entity_type'     => null,
                    'entity_id'       => null,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'  => $exchange->id,
                    'due_date'        => now(),
                ]);
            }

            // Participación del Inversionista (si aplica)
            if ($capitalType === 'investor' && !empty($data['investor_profit_amount']) && !empty($data['investor_id'])) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'       => $tenantId,
                    'description'     => "Participación inversionista - Intercambio {$exchangeNumber}",
                    'amount'          => $data['investor_profit_amount'],
                    'original_amount' => $data['investor_profit_amount'],
                    'paid_amount'     => 0,
                    'currency_type'   => $currencyReceived,
                    'type'            => 'payable',
                    'status'          => 'pending',
                    'entity_type'     => Investor::class,
                    'entity_id'       => $data['investor_id'],
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'  => $exchange->id,
                    'due_date'        => now(),
                ]);
            }

            return $exchange->fresh([
                'client',
                'broker.user',
                'provider',
                'adminUser',
                'fromAccount',
                'toAccount',
                'investor'
            ]);
        });
    }

    /**
     * Crea un movimiento interno simple (ingreso o gasto manual)
     */
    public function createInternalTransaction(array $data)
    {
        return DB::transaction(function () use ($data) {
            $account = Account::lockForUpdate()->findOrFail($data['account_id']);

            if ($data['type'] === 'expense' && $account->balance < $data['amount']) {
                throw new Exception("Saldo insuficiente en la cuenta {$account->name}");
            }

            if ($data['type'] === 'expense') {
                $account->decrement('balance', $data['amount']);
            } else {
                $account->increment('balance', $data['amount']);
            }

            return InternalTransaction::create([
                'tenant_id'        => Auth::user()->tenant_id ?? 1,
                'user_id'          => $data['user_id'],
                'account_id'       => $data['account_id'],
                'type'             => $data['type'],
                'category'         => $data['category'],
                'amount'           => $data['amount'],
                'currency_code'    => $account->currency_code,             // ← NUEVO
                'description'      => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'dueño'            => $data['dueño'] ?? null,
                'person_name'      => $data['person_name'] ?? null,
            ]);
        });
    }

    /**
     * Procesa un pago/abono a un asiento contable
     */
    public function processLedgerPayment(LedgerEntry $entry, int $accountId, float $amount, ?string $description = null)
    {
        return DB::transaction(function () use ($entry, $accountId, $amount, $description) {
            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $amount) {
                    throw new Exception("Saldo insuficiente en {$account->name}");
                }
                $account->decrement('balance', $amount);
                $txType   = 'expense';
                $category = 'Pago de Deuda';
            } else {
                $account->increment('balance', $amount);
                $txType   = 'income';
                $category = 'Cobro de Crédito';
            }

            // Registrar movimiento interno
            $internalTx = InternalTransaction::create([
                'tenant_id'        => $entry->tenant_id,
                'user_id'          => Auth::id(),
                'account_id'       => $accountId,
                'type'             => $txType,
                'category'         => $category,
                'amount'           => $amount,
                'currency_code'    => $account->currency_code,             // ← NUEVO
                'description'      => $description ?? "Abono a asiento #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
            ]);

            // Registrar abono
            $payment = $entry->payments()->create([
                'account_id'   => $accountId,
                'user_id'      => Auth::id(),
                'amount'       => $amount,
                'currency_type' => $account->currency_code,                 // ← NUEVO
                'description'  => $description,
                'payment_date' => now(),
            ]);

            // Actualizar monto pagado
            $entry->increment('paid_amount', $amount);

            return $internalTx;
        });
    }

    /**
     * Agrega saldo a favor (Fondeo / Recarga Manual) a un Proveedor o Inversionista.
     */
    public function addBalanceToEntity($entity, float $amount, ?string $description = 'Recarga de saldo')
    {
        return DB::transaction(function () use ($entity, $amount, $description) {
            // Como es un fondeo manual, no hay cuenta específica → usamos una moneda por defecto o null
            // Si en el futuro quieres especificar moneda, puedes pasar un parámetro extra.
            // Por ahora mantenemos como estaba (currency_type puede ser null o se asigna después).

            return $entity->ledgerEntries()->create([
                'tenant_id'        => Auth::user()->tenant_id ?? 1,
                'description'      => $description,
                'amount'           => $amount,
                'original_amount'  => $amount,
                'paid_amount'      => 0,
                'currency_type'    => null, // Puedes mejorarlo más adelante si necesitas especificar
                'type'             => 'payable',
                'status'           => 'pending',
                'due_date'         => now(),
                'transaction_type' => get_class($entity),
                'transaction_id'   => $entity->id,
            ]);
        });
    }
}
