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

                // Registro Historial Caja (Salida)
                InternalTransaction::create([
                    'tenant_id'   => $tenantId,
                    'user_id'     => $data['admin_user_id'],
                    'account_id'  => $fromAccount->id,
                    'type'        => 'expense',
                    'category'    => 'Intercambio Enviado',
                    'amount'      => $amountSent,
                    'description' => "Salida Intercambio {$exchangeNumber}",
                    'transaction_date' => now(),
                ]);
            }

            // -------------------------------------------------------------
            // B. LÓGICA DE CUENTA DESTINO (SIEMPRE ENTRA DINERO)
            // -------------------------------------------------------------
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $toAccount->increment('balance', $amountReceived);

            // Registro Historial Caja (Entrada)
            InternalTransaction::create([
                'tenant_id'   => $tenantId,
                'user_id'     => $data['admin_user_id'],
                'account_id'  => $toAccount->id,
                'type'        => 'income',
                'category'    => 'Intercambio Recibido',
                'amount'      => $amountReceived,
                'description' => "Entrada Intercambio {$exchangeNumber}" . ($fromAccount ? " desde {$fromAccount->name}" : " (Inversionista)"),
                'transaction_date' => now(),
            ]);

            // -------------------------------------------------------------
            // C. CREAR EL MODELO PRINCIPAL
            // -------------------------------------------------------------

            $exchange = CurrencyExchange::create([
                'tenant_id'                  => $tenantId,
                'number'                     => $exchangeNumber,
                'client_id'                  => $data['client_id'] ?? null,
                'broker_id'                  => $data['broker_id'] ?? null,
                'provider_id'                => $data['provider_id'] ?? null,
                'platform_id'                => $data['platform_id'] ?? null,
                'admin_user_id'              => $data['admin_user_id'],

                'from_account_id'            => $fromAccountId, // ID o NULL
                'to_account_id'              => $toAccount->id,
                'amount_sent'                => $amountSent,
                'amount_received'            => $amountReceived,
                'exchange_rate'              => $data['exchange_rate'],
                'buy_rate'                   => $data['buy_rate'] ?? null,
                'received_rate'              => $data['received_rate'] ?? null,

                'commission_total_amount'    => $data['commission_total_amount'] ?? 0,
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount'    => $data['commission_admin_amount'] ?? 0,

                // --- CAMPOS DE INVERSIONISTA ---
                'capital_type'               => $capitalType,
                'investor_id'                => $data['investor_id'] ?? null,
                'investor_profit_pct'        => $data['investor_profit_pct'] ?? 0,
                'investor_profit_amount'     => $data['investor_profit_amount'] ?? 0,

                'reference_id'               => $data['reference_id'] ?? null,
                'status'                     => $data['status'] ?? 'completed',
            ]);

            // =================================================================
            // D. GENERAR ASIENTOS (CUENTAS POR PAGAR)
            // =================================================================

            // D.1. Comisión PROVEEDOR (CxP)
            $providerCommAmount = (float) ($data['commission_provider_amount'] ?? 0);
            $providerId         = $data['provider_id'] ?? null;
            if ($providerCommAmount > 0 && $providerId) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'   => $exchange->tenant_id,
                    'description' => "Comisión Proveedor (Op. #{$exchange->number})",
                    'amount'           => $providerCommAmount,
                    'type'             => 'payable',
                    'status'           => 'pending',
                    'entity_id'        => $providerId,
                    'entity_type'      => Provider::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'   => $exchange->id,
                ]);
            }

            // D.2. Comisión CORREDOR/BROKER (CxP)
            $brokerCommAmount = (float) ($data['commission_broker_amount'] ?? 0);
            $brokerId         = $data['broker_id'] ?? null;
            if ($brokerCommAmount > 0 && $brokerId) {
                $broker = Broker::find($brokerId);
                if ($broker) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id'   => $exchange->tenant_id,
                        'description' => "Comisión Corredor (Op. #{$exchange->number})",
                        'amount'           => $brokerCommAmount,
                        'type'             => 'payable',
                        'status'           => 'pending',
                        'entity_id'        => $broker->id,
                        'entity_type'      => Broker::class,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id'   => $exchange->id,
                    ]);
                }
            }

            // D.3. Costo PLATAFORMA (CxP)
            $platformCommAmount = (float) ($data['commission_admin_amount'] ?? 0);
            $platformId         = $data['platform_id'] ?? null;
            if ($platformCommAmount > 0 && $platformId) {
                $exchange->ledgerEntries()->create([
                    'tenant_id'   => $exchange->tenant_id,
                    'description' => "Costo Plataforma (Op. #{$exchange->number})",
                    'amount'           => $platformCommAmount,
                    'type'             => 'payable',
                    'status'           => 'pending',
                    'entity_id'        => $platformId,
                    'entity_type'      => Platform::class,
                    'transaction_type' => CurrencyExchange::class,
                    'transaction_id'   => $exchange->id,
                ]);
            }

            // =================================================================
            // E. LÓGICA DE INVERSIONISTA (Solo Informativo)
            // =================================================================
            // MODIFICACIÓN: No generamos asientos contables ni alteramos saldo.
            // La rentabilidad se calcula por fecha (Interés Compuesto) y no por operación.
            // La relación ya quedó guardada en el modelo CurrencyExchange (investor_id) en el paso C.
            if ($capitalType === 'investor' && ! empty($data['investor_id'])) {
                // Aquí podrías validar si el inversionista existe o está activo si lo deseas,
                // pero no creamos ledgerEntries para no duplicar deuda.
            }

            // D.4. Comisión Ganada (CxC - Opcional)
            $companyCommAmount = (float) ($data['commission_charged_amount'] ?? 0);
            if ($companyCommAmount > 0 && ! empty($exchange->client_id)) {
                $client     = Client::find($exchange->client_id);
                $isDeferred = $data['is_commission_deferred'] ?? false;
                $status     = $isDeferred ? 'pending' : 'paid';

                if ($client) {
                    $exchange->ledgerEntries()->create([
                        'tenant_id'   => $exchange->tenant_id,
                        'description' => "Comisión de Casa - Op. #{$exchange->number}",
                        'amount'           => $companyCommAmount,
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

    /**
     * Pago de deudas (Sin cambios mayores, lógica genérica)
     */
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
                'description'      => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now(),
                'dueño'            => $data['dueño'] ?? null,
                'person_name'      => $data['person_name'] ?? null,
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

            // Registrar movimiento interno
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

            // Registrar abono
            $payment = $entry->payments()->create([
                'account_id'   => $accountId,
                'user_id'      => Auth::id(),
                'amount'       => $amount,
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
     * Crea un registro 'payable' en el Ledger (La empresa le debe dinero al usuario).
     * * @param mixed $entity Instancia de Provider o Investor
     * @param float $amount Monto a agregar
     * @param string|null $description Descripción del movimiento
     */
    public function addBalanceToEntity($entity, float $amount, ?string $description = 'Recarga de saldo')
    {
        return DB::transaction(function () use ($entity, $amount, $description) {
            
            return $entity->ledgerEntries()->create([
                'tenant_id'        => Auth::user()->tenant_id ?? 1,
                'description'      => $description,
                'amount'           => $amount,
                'original_amount'  => $amount,
                'paid_amount'      => 0,
                'type'             => 'payable', // Payable = Saldo a favor (Deuda de la empresa hacia la entidad)
                'status'           => 'pending',
                'due_date'         => now(),
                'transaction_type' => get_class($entity),
                'transaction_id'   => $entity->id,
            ]);
        });
    }
}