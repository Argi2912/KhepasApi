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

    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            $exchangeNumber = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');
            $tenantId       = Auth::user()->tenant_id ?? 1;

            $fromAccount    = null;
            $fromAccountId  = null;
            $capitalType    = $data['capital_type'] ?? 'own';
            $amountSent     = (float) $data['amount_sent'];
            $amountReceived = (float) $data['amount_received'];

            // 1. Lógica de Salida de Dinero (Capital Propio)
            if ($capitalType === 'own') {
                if (empty($data['from_account_id'])) {
                    throw new Exception("Se requiere una cuenta de origen para capital propio.");
                }

                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);

                if ($fromAccount->balance < $amountSent) {
                    throw new Exception("Saldo insuficiente en {$fromAccount->name} para enviar {$amountSent} {$fromAccount->currency_code}.");
                }

                $fromAccount->decrement('balance', $amountSent);
                $fromAccountId = $fromAccount->id;

                InternalTransaction::create([
                    'tenant_id'        => $tenantId,
                    'user_id'          => $data['admin_user_id'],
                    'account_id'       => $fromAccount->id,
                    'type'             => 'expense',
                    'category'         => 'Intercambio Enviado',
                    'amount'           => $amountSent,
                    'description'      => "Salida Intercambio {$exchangeNumber}",
                    'transaction_date' => now(),
                ]);
            }

            // 2. Lógica de Entrada de Dinero (Cuenta Destino)
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $toAccount->increment('balance', $amountReceived);

            InternalTransaction::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $data['admin_user_id'],
                'account_id'       => $toAccount->id,
                'type'             => 'income',
                'category'         => 'Intercambio Recibido',
                'amount'           => $amountReceived,
                'description'      => "Entrada Intercambio {$exchangeNumber}" . ($fromAccount ? " desde {$fromAccount->name}" : " (Inversionista)"),
                'transaction_date' => now(),
            ]);

            // 3. Crear Registro Principal de Intercambio
            $exchange = CurrencyExchange::create([
                'tenant_id'                  => $tenantId,
                'number'                     => $exchangeNumber,
                'type'                       => $data['type'] ?? 'exchange',
                'client_id'                  => $data['client_id'] ?? null,
                'broker_id'                  => $data['broker_id'] ?? null,
                'provider_id'                => $data['provider_id'] ?? null,
                'platform_id'                => $data['platform_id'] ?? null,
                'admin_user_id'              => $data['admin_user_id'],
                'from_account_id'            => $fromAccountId,
                'to_account_id'              => $toAccount->id,
                'amount_sent'                => $amountSent,
                'amount_received'            => $amountReceived,
                'exchange_rate'              => $data['exchange_rate'],
                'buy_rate'                   => $data['buy_rate'] ?? null,
                'received_rate'              => $data['received_rate'] ?? null,
                'commission_total_amount'    => $data['commission_total_amount'] ?? 0,
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount'    => $data['commission_admin_amount'] ?? 0,
                'capital_type'               => $capitalType,
                'investor_id'                => $data['investor_id'] ?? null,
                'investor_profit_pct'        => $data['investor_profit_pct'] ?? 0,
                'investor_profit_amount'     => $data['investor_profit_amount'] ?? 0,
                'reference_id'               => $data['reference_id'] ?? null,
                'status'                     => $data['status'] ?? 'completed',
            ]);

            // --- DETERMINAR MONEDAS PARA ASIENTOS CONTABLES ---
            // Moneda de Salida: Si hay cuenta origen, es su moneda. Si no (Inversionista), asumimos la moneda destino por defecto o se podría ajustar.
            $currencyOut = $fromAccount ? $fromAccount->currency_code : $toAccount->currency_code;
            // Moneda de Entrada: Siempre es la moneda de la cuenta destino.
            $currencyIn  = $toAccount->currency_code;

            // D.1. Comisión PROVEEDOR (Se paga en la moneda que sale)
            $providerCommAmount = (float) ($data['commission_provider_amount'] ?? 0);
            $providerId         = $data['provider_id'] ?? null;
            if ($providerCommAmount > 0 && $providerId) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'        => $exchange->tenant_id,
                    'description'      => "Comisión Proveedor (Op. #{$exchange->number})",
                    'amount'           => $providerCommAmount,
                    'currency_code'    => $currencyOut, // <--- MODIFICADO
                    'type'             => 'payable',
                    'status'           => 'pending',
                    'entity_id'        => $providerId,
                    'entity_type'      => Provider::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'   => $exchange->id,
                ]);
            }

            // D.2. Comisión BROKER (Se paga en la moneda que sale)
            $brokerCommAmount = (float) ($data['commission_broker_amount'] ?? 0);
            $brokerId         = $data['broker_id'] ?? null;
            if ($brokerCommAmount > 0 && $brokerId) {
                $broker = Broker::find($brokerId);
                if ($broker) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id'        => $exchange->tenant_id,
                        'description'      => "Comisión Corredor (Op. #{$exchange->number})",
                        'amount'           => $brokerCommAmount,
                        'currency_code'    => $currencyOut, // <--- MODIFICADO
                        'type'             => 'payable',
                        'status'           => 'pending',
                        'entity_id'        => $broker->id,
                        'entity_type'      => Broker::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id'   => $exchange->id,
                    ]);
                }
            }

            // D.3. Costo PLATAFORMA (Se paga en la moneda que sale)
            $platformCommAmount = (float) ($data['commission_admin_amount'] ?? 0);
            $platformId         = $data['platform_id'] ?? null;
            if ($platformCommAmount > 0 && $platformId) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'        => $exchange->tenant_id,
                    'description'      => "Costo Plataforma (Op. #{$exchange->number})",
                    'amount'           => $platformCommAmount,
                    'currency_code'    => $currencyOut, // <--- MODIFICADO
                    'type'             => 'payable',
                    'status'           => 'pending',
                    'entity_id'        => $platformId,
                    'entity_type'      => Platform::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'   => $exchange->id,
                ]);
            }

            // D.4. Comisión Ganada (Se cobra en la moneda que entra - destino)
            $companyCommAmount = (float) ($data['commission_charged_amount'] ?? 0);
            if ($companyCommAmount > 0 && ! empty($exchange->client_id)) {
                $client     = Client::find($exchange->client_id);
                $isDeferred = $data['is_commission_deferred'] ?? false;
                $status     = $isDeferred ? 'pending' : 'paid';

                if ($client) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id'        => $exchange->tenant_id,
                        'description'      => "Comisión de Casa - Op. #{$exchange->number}",
                        'amount'           => $companyCommAmount,
                        'currency_code'    => $currencyIn, // <--- MODIFICADO (Moneda entrante)
                        'type'             => 'receivable',
                        'status'           => $status,
                        'entity_id'        => $client->id,
                        'entity_type'      => Client::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id'   => $exchange->id,
                    ]);
                }
            }

            return $exchange->load('client', 'fromAccount', 'toAccount', 'investor');
        });
    }

    public function processDebtPayment(LedgerEntry $entry, int $accountId)
    {
        return DB::transaction(function () use ($entry, $accountId) {
            if ($entry->status === 'paid') {
                throw new Exception("Este asiento ya fue procesado anteriormente.");
            }

            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $entry->amount) {
                    throw new Exception("Saldo insuficiente en {$account->name} para pagar esta deuda.");
                }
                $account->decrement('balance', $entry->amount);
                $txType     = 'expense';
                $category   = 'Pago de Deuda';
                $descPrefix = "Pago de deuda";
            } else {
                $account->increment('balance', $entry->amount);
                $txType     = 'income';
                $category   = 'Cobro de Deuda';
                $descPrefix = "Cobro de crédito";
            }

            $internalTx = InternalTransaction::create([
                'tenant_id'   => $entry->tenant_id,
                'user_id'     => Auth::id(),
                'account_id'  => $account->id,
                'type'        => $txType,
                'category'    => $category,
                'amount'      => $entry->amount,
                'description' => "{$descPrefix} #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
            ]);

            $entry->update(['status' => 'paid']);

            return $internalTx;
        });
    }

    // 🔥 ESTA ES LA FUNCIÓN CLAVE QUE NECESITABA AJUSTE 🔥
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
                'tenant_id'        => Auth::user()->tenant_id ?? 1,
                'user_id'          => $data['user_id'],
                'account_id'       => $data['account_id'],
                'type'             => $data['type'],
                'category'         => $data['category'],
                'amount'           => $data['amount'],
                'description'      => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),

                // ✅ AQUÍ AGREGAMOS LOS CAMPOS PARA QUE SE GUARDEN EN LA BD
                'dueño'            => $data['dueño'] ?? null,
                'person_name'      => $data['person_name'] ?? null,
                'entity_type'      => $data['entity_type'] ?? null,
                'entity_id'        => $data['entity_id'] ?? null,
            ]);
        });
    }

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

            $internalTx = InternalTransaction::create([
                'tenant_id'   => $entry->tenant_id,
                'user_id'     => Auth::id(),
                'account_id'  => $accountId,
                'type'        => $txType,
                'category'    => $category,
                'amount'      => $amount,
                'description' => $description ?? "Abono a asiento #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
            ]);

            $entry->payments()->create([
                'account_id'   => $accountId,
                'user_id'      => Auth::id(),
                'amount'       => $amount,
                'description'  => $description,
                'payment_date' => now(),
            ]);

            $entry->increment('paid_amount', $amount);

            return $internalTx;
        });
    }

    public function addBalanceToEntity($entity, float $amount, ?string $description = 'Recarga de saldo')
    {
        return DB::transaction(function () use ($entity, $amount, $description) {
            return $entity->ledgerEntries()->create([
                'tenant_id'        => Auth::user()->tenant_id ?? 1,
                'description'      => $description,
                'amount'           => $amount,
                'original_amount'  => $amount,
                'paid_amount'      => 0,
                'type'             => 'payable',
                'status'           => 'pending',
                'due_date'         => now(),
                'transaction_type' => get_class($entity),
                'transaction_id'   => $entity->id,
            ]);
        });
    }
}
