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

    // =========================================================================
    // 1. GESTIÓN DE OPERACIONES DE CAMBIO
    // =========================================================================
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

            // DETECCIÓN DE ESTADO
            $statusRaw = $data['status'] ?? 'completed';
            if (isset($data['delivered']) && !$data['delivered']) {
                $statusRaw = 'waiting_payment';
            }
            $isPaidNow = ($statusRaw === 'completed');

            // Money Out
            if ($capitalType === 'own') {
                if (empty($data['from_account_id'])) throw new Exception("Se requiere cuenta origen.");
                // LockForUpdate para evitar condiciones de carrera en Exchanges
                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);

                if ($fromAccount->balance < $amountSent) {
                    throw new Exception("Saldo insuficiente en {$fromAccount->name}.");
                }

                $fromAccount->decrement('balance', $amountSent);
                $fromAccountId = $fromAccount->id;

                InternalTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $data['admin_user_id'],
                    'account_id' => $fromAccount->id,
                    'type' => 'expense',
                    'category' => 'Intercambio Enviado',
                    'amount' => $amountSent,
                    'description' => "Salida Intercambio {$exchangeNumber}",
                    'transaction_date' => now()
                ]);
            }

            // Money In
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            if ($isPaidNow) {
                $toAccount->increment('balance', $amountReceived);
                InternalTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $data['admin_user_id'],
                    'account_id' => $toAccount->id,
                    'type' => 'income',
                    'category' => 'Intercambio Recibido',
                    'amount' => $amountReceived,
                    'description' => "Entrada Intercambio {$exchangeNumber}",
                    'transaction_date' => now()
                ]);
            }

            // Registro Exchange
            $exchange = CurrencyExchange::create([
                'tenant_id' => $tenantId,
                'number' => $exchangeNumber,
                'type' => $data['type'] ?? 'exchange',
                'client_id' => $data['client_id'] ?? null,
                'broker_id' => $data['broker_id'] ?? null,
                'provider_id' => $data['provider_id'] ?? null,
                'platform_id' => $data['platform_id'] ?? null,
                'admin_user_id' => $data['admin_user_id'],
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccount->id,
                'amount_sent' => $amountSent,
                'amount_received' => $amountReceived,
                'exchange_rate' => $data['exchange_rate'],
                'buy_rate' => $data['buy_rate'] ?? null,
                'received_rate' => $data['received_rate'] ?? null,
                'commission_total_amount' => $data['commission_total_amount'] ?? 0,
                'commission_provider_amount' => $data['commission_provider_amount'] ?? 0,
                'commission_admin_amount' => $data['commission_admin_amount'] ?? 0,
                'capital_type' => $capitalType,
                'investor_id' => $data['investor_id'] ?? null,
                'investor_profit_pct' => $data['investor_profit_pct'] ?? 0,
                'investor_profit_amount' => $data['investor_profit_amount'] ?? 0,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => $statusRaw
            ]);

            // COMISIONES
            $currencyOut = $fromAccount ? $fromAccount->currency_code : $toAccount->currency_code;
            $currencyIn  = $toAccount->currency_code;

            if (($p = (float)($data['commission_provider_amount'] ?? 0)) > 0 && !empty($data['provider_id']))
                $this->createLedgerDebt($exchange, $p, $currencyOut, 'payable', $data['provider_id'], Provider::class, "Comisión Proveedor");

            if (($b = (float)($data['commission_broker_amount'] ?? 0)) > 0 && !empty($data['broker_id']))
                $this->createLedgerDebt($exchange, $b, $currencyOut, 'payable', $data['broker_id'], Broker::class, "Comisión Corredor");

            if (($pl = (float)($data['commission_admin_amount'] ?? 0)) > 0 && !empty($data['platform_id']))
                $this->createLedgerDebt($exchange, $pl, $currencyOut, 'payable', $data['platform_id'], Platform::class, "Costo Plataforma");

            if (($c = (float)($data['commission_charged_amount'] ?? 0)) > 0 && !empty($exchange->client_id)) {
                $status = ($data['is_commission_deferred'] ?? false) || !$isPaidNow ? 'pending' : 'paid';
                $this->createLedgerDebt($exchange, $c, $currencyIn, 'receivable', $exchange->client_id, Client::class, "Comisión de Casa", $status);
            }

            // Siempre registra en Ledger, sea deuda o historial pagado
            if (!empty($exchange->client_id) && $amountReceived > 0) {
                $ledgerStatus = $isPaidNow ? 'paid' : 'pending';

                $this->createLedgerDebt(
                    $exchange,
                    $amountReceived,
                    $currencyIn,
                    'receivable',
                    $exchange->client_id,
                    Client::class,
                    "Operación de Cambio #{$exchange->number}",
                    $ledgerStatus
                );
            }

            return $exchange->load('client');
        });
    }

    private function createLedgerDebt($exchange, $amount, $currency, $type, $entityId, $entityType, $desc, $status = 'pending')
    {
        $exchange->ledgerEntries()->create([
            'tenant_id' => $exchange->tenant_id,
            'description' => "$desc (Op. #{$exchange->number})",
            'amount' => $amount,
            'original_amount' => $amount,
            'paid_amount' => ($status == 'paid' ? $amount : 0),
            'currency_code' => $currency,
            'type' => $type,
            'status' => $status,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'transaction_type' => CurrencyExchange::class,
            'transaction_id' => $exchange->id,
            'due_date' => now()
        ]);
    }

    // =========================================================================
    // 2. GESTIÓN DE MOVIMIENTOS INTERNOS (LÓGICA SIMÉTRICA DE LEDGER)
    // =========================================================================
    public function createInternalTransaction(array $data)
    {
        return DB::transaction(function () use ($data) {

            $sourceType = $data['source_type'] ?? 'account';
            $amount     = (float) $data['amount'];
            $type       = $data['type']; // income (Entrada) o expense (Salida)

            // Variable para la moneda de la operación
            $transactionCurrency = 'USD';

            // -----------------------------------------------------------------
            // A. GESTIÓN DE SALDO BANCARIO
            // -----------------------------------------------------------------
            if ($sourceType === 'account') {
                $account = Account::lockForUpdate()->find($data['account_id']);
                if (!$account) throw new Exception("Cuenta bancaria no encontrada.");

                $transactionCurrency = $account->currency_code;

                if ($type === 'expense') {
                    if ($account->balance < $amount) {
                        throw new Exception("Saldo insuficiente en cuenta {$account->name}.");
                    }
                    $account->decrement('balance', $amount);
                } else {
                    $account->increment('balance', $amount);
                }
            }

            // -----------------------------------------------------------------
            // B. GESTIÓN DE LEDGER (DEUDAS E HISTORIAL)
            // -----------------------------------------------------------------
            $entity = null;
            $entityType = $data['entity_type'] ?? null;
            $entityId   = $data['entity_id'] ?? null;

            // Mapeo rápido de source_type a Entidades si no vienen explícitas
            if ($sourceType === 'investor') {
                $entityType = Investor::class;
                $entityId   = $data['account_id'];
            } elseif ($sourceType === 'provider') {
                $entityType = Provider::class;
                $entityId   = $data['account_id'];
            }

            if ($entityType && $entityId) {
                $entity = $entityType::lockForUpdate()->find($entityId);
            }

            // PROCESAMIENTO
            if ($entity) {
                $remainingAmount = $amount;
                $desc = $data['description'] ?? 'Movimiento Interno';

                // --- CASO 1: INGRESO (Dinero entra a Caja) ---
                if ($type === 'income') {

                    // Si es Inversor -> Aumenta Capital (Deuda Pendiente siempre)
                    if ($entity instanceof Investor) {
                        $entity->increment('available_balance', $amount);
                        LedgerEntry::create([
                            'tenant_id' => Auth::user()->tenant_id ?? 1,
                            'entity_type' => get_class($entity),
                            'entity_id' => $entity->id,
                            'type' => 'payable',
                            'amount' => $amount,
                            'original_amount' => $amount,
                            'paid_amount' => 0,
                            'status' => 'pending',
                            'currency_code' => $transactionCurrency,
                            'description' => $desc,
                            'due_date' => now(),
                        ]);
                    }
                    // Si NO es inversor -> Intentamos cobrar deudas viejas o crear registro "Pagado"
                    else {
                        // Buscamos 'Receivables' (Cuentas por Cobrar) pendientes
                        $ledgers = LedgerEntry::where('entity_type', get_class($entity))
                            ->where('entity_id', $entity->id)
                            ->where('type', 'receivable')
                            ->where('status', '!=', 'paid')
                            ->orderBy('created_at', 'asc')
                            ->lockForUpdate()
                            ->get();

                        foreach ($ledgers as $ledger) {
                            if ($remainingAmount <= 0) break;
                            $pending = $ledger->amount - $ledger->paid_amount;

                            if ($pending <= $remainingAmount) {
                                $ledger->paid_amount += $pending;
                                $ledger->status = 'paid';
                                $remainingAmount -= $pending;
                            } else {
                                $ledger->paid_amount += $remainingAmount;
                                $ledger->status = 'partially_paid';
                                $remainingAmount = 0;
                            }
                            $ledger->save();
                        }

                        // Si sobró dinero (o no había deuda), creamos un registro "Spot" (Pagado al momento)
                        if ($remainingAmount > 0) {
                            LedgerEntry::create([
                                'tenant_id' => Auth::user()->tenant_id ?? 1,
                                'entity_type' => get_class($entity),
                                'entity_id' => $entity->id,
                                'type' => 'receivable', // Fue un derecho de cobro...
                                'amount' => $remainingAmount,
                                'original_amount' => $remainingAmount,
                                'paid_amount' => $remainingAmount, // ...que se pagó inmediatamente
                                'status' => 'paid',
                                'currency_code' => $transactionCurrency,
                                'description' => "$desc (Contado)",
                                'due_date' => now(),
                            ]);
                        }
                    }
                }

                // --- CASO 2: EGRESO (Dinero sale de Caja) ---
                if ($type === 'expense') {

                    // Buscamos 'Payables' (Cuentas por Pagar) pendientes
                    $ledgers = LedgerEntry::where('entity_type', get_class($entity))
                        ->where('entity_id', $entity->id)
                        ->where('type', 'payable')
                        ->where('status', '!=', 'paid')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->get();

                    // Validación especial Inversor (Retiro de Capital)
                    if ($entity instanceof Investor) {
                        $totalDebt = $ledgers->sum(fn($l) => $l->amount - $l->paid_amount);
                        if ($totalDebt < $amount) {
                            throw new Exception("Saldo insuficiente en Inversionista. Disponible: $totalDebt");
                        }
                    }

                    // Amortizamos deuda existente
                    foreach ($ledgers as $ledger) {
                        if ($remainingAmount <= 0) break;
                        $pending = $ledger->amount - $ledger->paid_amount;

                        if ($pending <= $remainingAmount) {
                            $ledger->paid_amount += $pending;
                            $ledger->status = 'paid';
                            $remainingAmount -= $pending;
                        } else {
                            $ledger->paid_amount += $remainingAmount;
                            $ledger->status = 'partially_paid';
                            $remainingAmount = 0;
                        }
                        $ledger->save();
                    }

                    // Si sobró dinero y NO es inversor -> Gasto al Contado (Spot Payment)
                    if ($remainingAmount > 0 && !($entity instanceof Investor)) {
                        LedgerEntry::create([
                            'tenant_id' => Auth::user()->tenant_id ?? 1,
                            'entity_type' => get_class($entity),
                            'entity_id' => $entity->id,
                            'type' => 'payable', // Era una obligación de pago...
                            'amount' => $remainingAmount,
                            'original_amount' => $remainingAmount,
                            'paid_amount' => $remainingAmount, // ...que saldamos al momento
                            'status' => 'paid',
                            'currency_code' => $transactionCurrency,
                            'description' => "$desc (Contado)",
                            'due_date' => now(),
                        ]);
                    }
                }
            }

            // -----------------------------------------------------------------
            // C. TRANSFERENCIAS ENTRE CUENTAS
            // -----------------------------------------------------------------
            if ($type === 'expense' && ($data['entity_type'] ?? '') === 'App\Models\Account') {
                $destId = $data['entity_id'];
                if ($data['account_id'] == $destId && $sourceType === 'account') {
                    throw new Exception("No puedes transferir a la misma cuenta.");
                }
                $destAccount = Account::lockForUpdate()->find($destId);
                if ($destAccount) {
                    if ($sourceType === 'account' && isset($account)) {
                        if ($account->currency_code !== $destAccount->currency_code) {
                            throw new Exception("Error de Divisa: Incompatibilidad.");
                        }
                    }
                    $destAccount->increment('balance', $amount);
                }
            }

            // -----------------------------------------------------------------
            // D. REGISTRO HISTÓRICO
            // -----------------------------------------------------------------
            $transaction = InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => $data['user_id'],
                'account_id' => $data['account_id'],
                'source_type' => $sourceType,
                'type' => $data['type'],
                'category' => $data['category'],
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'dueño' => $data['dueño'] ?? null,
                'person_name' => $data['person_name'] ?? null,
                'entity_type' => $entityType,
                'entity_id'   => $entityId
            ]);

            return $transaction;
        });
    }

    // =========================================================================
    // 3. INTERÉS COMPUESTO
    // =========================================================================
    public function applyCompoundInterest(int $investorId, float $amount, string $description = 'Interés Compuesto')
    {
        return DB::transaction(function () use ($investorId, $amount, $description) {
            $investor = Investor::lockForUpdate()->findOrFail($investorId);
            $investor->increment('available_balance', $amount);

            InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => Auth::id(),
                'account_id' => $investorId,
                'source_type' => 'investor',
                'type' => 'info',
                'category' => 'Interés Compuesto',
                'amount' => $amount,
                'description' => $description,
                'transaction_date' => now(),
                'entity_type' => Investor::class,
                'entity_id' => $investorId
            ]);
        });
    }

    // =========================================================================
    // 4. AUXILIARES DE PAGO DE DEUDA
    // =========================================================================
    public function processDebtPayment(LedgerEntry $entry, int $accountId)
    {
        return DB::transaction(function () use ($entry, $accountId) {
            if ($entry->status === 'paid') throw new Exception("Esta deuda ya está pagada.");

            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $entry->amount) throw new Exception("Saldo insuficiente en caja.");
                $account->decrement('balance', $entry->amount);
                $txType = 'expense';
                $cat = 'Pago de Deuda';
            } else {
                $account->increment('balance', $entry->amount);
                $txType = 'income';
                $cat = 'Cobro de Deuda';
            }

            $tx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id,
                'user_id' => Auth::id(),
                'account_id' => $account->id,
                'type' => $txType,
                'category' => $cat,
                'amount' => $entry->amount,
                'description' => "Pago/Cobro #{$entry->id}: {$entry->description}",
                'transaction_date' => now()
            ]);

            $entry->update([
                'status' => 'paid',
                'paid_amount' => $entry->amount
            ]);

            return $tx;
        });
    }

    public function processLedgerPayment(LedgerEntry $entry, int $accountId, float $amount, ?string $description = null)
    {
        return DB::transaction(function () use ($entry, $accountId, $amount, $description) {
            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $amount) throw new Exception("Saldo insuficiente para abono.");
                $account->decrement('balance', $amount);
                $txType = 'expense';
                $cat = 'Pago de Deuda';
            } else {
                $account->increment('balance', $amount);
                $txType = 'income';
                $cat = 'Cobro de Crédito';
            }

            $tx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id,
                'user_id' => Auth::id(),
                'account_id' => $accountId,
                'type' => $txType,
                'category' => $cat,
                'amount' => $amount,
                'description' => $description ?? "Abono Ledger",
                'transaction_date' => now()
            ]);

            $entry->payments()->create([
                'account_id' => $accountId,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'description' => $description,
                'payment_date' => now()
            ]);

            $entry->increment('paid_amount', $amount);

            if ($entry->paid_amount >= ($entry->original_amount - 0.01)) {
                $entry->update(['status' => 'paid']);
            } else {
                $entry->update(['status' => 'partially_paid']);
            }

            return $tx;
        });
    }

    public function addBalanceToEntity($entity, float $amount, string $currencyCode, ?string $description = 'Recarga')
    {
        return DB::transaction(function () use ($entity, $amount, $currencyCode, $description) {
            return $entity->ledgerEntries()->create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'description' => $description,
                'amount' => $amount,
                'original_amount' => $amount,
                'paid_amount' => 0,
                'type' => 'payable',
                'status' => 'pending',
                'due_date' => now(),
                'transaction_type' => get_class($entity),
                'transaction_id' => $entity->id,
                'currency_code' => $currencyCode // Moneda dinámica correcta
            ]);
        });
    }
}
