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
    // =========================================================================
    // 0. GENERADOR DE SECUENCIALES (CORREGIDO)
    // =========================================================================
    private function generateSequentialNumber(string $modelClass, string $prefix): string
    {
        // 🚨 CORRECCIÓN CRÍTICA:
        // Agregamos `withTrashed()` para que cuente también los registros eliminados.
        // Esto evita que intente reutilizar el ID de una transacción borrada.
        $latest = $modelClass::withTrashed()->latest('id')->first();
        
        $nextId = $latest ? $latest->id + 1 : 1;
        return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // 1. GESTIÓN DE OPERACIONES DE CAMBIO (ACTUALIZADO: LOGICA PAID/DELIVERED)
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

            // LÓGICA DE ESTADOS
            // delivered (true) = Ya recibí los USD del cliente (o entregué el producto) -> Money In ocurre.
            // paid (true)      = Ya pagué los VES al cliente -> Money Out ocurre.
            $isDelivered = $data['delivered'] ?? true; 
            $isPaid      = $data['paid'] ?? true;       

            // --- 1. MONEY OUT (Salida de Dinero) ---
            // Si es 'own' (Caja propia) y está PAGADO -> Descontar Saldo
            if ($capitalType === 'own') {
                if (empty($data['from_account_id'])) throw new Exception("Se requiere cuenta origen.");
                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
                $fromAccountId = $fromAccount->id;

                if ($isPaid) {
                    if ($fromAccount->balance < $amountSent) {
                        throw new Exception("Saldo insuficiente en {$fromAccount->name}.");
                    }
                    $fromAccount->decrement('balance', $amountSent);

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
                // Si NO está pagado (Por Pagar) -> No descontamos, el sistema debe registrar deuda más abajo
            }

            // --- 2. MONEY IN (Entrada de Dinero) ---
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            
            if ($isDelivered) {
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
                'status' => $data['status'] ?? 'completed'
            ]);

            // --- 3. REGISTRO DE DEUDAS (LEDGER) ---
            // Monedas de referencia
            $currencySent = $fromAccount ? $fromAccount->currency_code : '???';
            $currencyReceived = $toAccount->currency_code;

            // A) POR PAGAR (Si desmarcaste "Pagado Inmediatamente")
            // Le debemos al Cliente el monto que íbamos a enviar (amount_sent)
            if (!$isPaid && $capitalType === 'own' && !empty($exchange->client_id)) {
                $this->createLedgerDebt(
                    $exchange, 
                    $amountSent, 
                    $currencySent, 
                    'payable', // Deuda (Pasivo)
                    $exchange->client_id, 
                    Client::class, 
                    "Por Pagar al Cliente (Op. {$exchangeNumber})", 
                    'pending'
                );
            }

            // B) POR COBRAR (Si desmarcaste "Entregar Inmediatamente")
            // El cliente nos debe el monto que íbamos a recibir (amount_received)
            if (!$isDelivered && !empty($exchange->client_id)) {
                $this->createLedgerDebt(
                    $exchange, 
                    $amountReceived, 
                    $currencyReceived, 
                    'receivable', // Cobro (Activo)
                    $exchange->client_id, 
                    Client::class, 
                    "Por Cobrar al Cliente (Op. {$exchangeNumber})", 
                    'pending'
                );
            }

            // C) COMISIONES Y TERCEROS
            if (($p = (float)($data['commission_provider_amount'] ?? 0)) > 0 && !empty($data['provider_id']))
                $this->createLedgerDebt($exchange, $p, $currencySent, 'payable', $data['provider_id'], Provider::class, "Comisión Proveedor");

            if (($b = (float)($data['commission_broker_amount'] ?? 0)) > 0 && !empty($data['broker_id']))
                $this->createLedgerDebt($exchange, $b, $currencySent, 'payable', $data['broker_id'], Broker::class, "Comisión Corredor");

            if (($pl = (float)($data['commission_admin_amount'] ?? 0)) > 0 && !empty($data['platform_id']))
                $this->createLedgerDebt($exchange, $pl, $currencySent, 'payable', $data['platform_id'], Platform::class, "Costo Plataforma");

            // Comisión de la casa (Solo si ya se cobró/entregó la operación se marca como pagada, si no queda pendiente)
            if (($c = (float)($data['commission_charged_amount'] ?? 0)) > 0 && !empty($exchange->client_id)) {
                $commStatus = $isDelivered ? 'paid' : 'pending';
                $this->createLedgerDebt($exchange, $c, $currencyReceived, 'receivable', $exchange->client_id, Client::class, "Comisión de Casa", $commStatus);
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
    // 2. GESTIÓN DE MOVIMIENTOS INTERNOS (CORREGIDO PROVEEDORES)
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
                    // Si NO es inversor (Proveedores, etc)
                    else {
                        // ... Lógica estándar de receivables ...
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

                        // Spot Payment si sobra dinero
                        if ($remainingAmount > 0) {
                            LedgerEntry::create([
                                'tenant_id' => Auth::user()->tenant_id ?? 1,
                                'entity_type' => get_class($entity),
                                'entity_id' => $entity->id,
                                'type' => 'receivable', 
                                'amount' => $remainingAmount,
                                'original_amount' => $remainingAmount,
                                'paid_amount' => $remainingAmount, 
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

                    // [AQUÍ ESTÁ LA CORRECCIÓN CLAVE PARA PROVEEDORES]
                    // Al retirar dinero del proveedor, CREAMOS LA DEUDA (payable).
                    if ($entity instanceof Provider) {
                        LedgerEntry::create([
                            'tenant_id' => Auth::user()->tenant_id ?? 1,
                            'entity_type' => get_class($entity),
                            'entity_id' => $entity->id,
                            'type' => 'payable', // <--- ESTO REGISTRA LA DEUDA
                            'amount' => $amount,
                            'original_amount' => $amount,
                            'paid_amount' => 0, // No está pagada, acabas de adquirir la deuda
                            'status' => 'pending',
                            'currency_code' => $transactionCurrency,
                            'description' => $desc,
                            'due_date' => now(),
                            'transaction_type' => InternalTransaction::class // Opcional, para rastreo
                        ]);
                    }
                    // LÓGICA ORIGINAL PARA INVERSORES (NO SE TOCA)
                    else {
                        $ledgers = LedgerEntry::where('entity_type', get_class($entity))
                            ->where('entity_id', $entity->id)
                            ->where('type', 'payable')
                            ->where('status', '!=', 'paid')
                            ->orderBy('created_at', 'asc')
                            ->lockForUpdate()
                            ->get();

                        if ($entity instanceof Investor) {
                            $totalDebt = $ledgers->sum(fn($l) => $l->amount - $l->paid_amount);
                            if ($totalDebt < $amount) {
                                throw new Exception("Saldo insuficiente en Inversionista. Disponible: $totalDebt");
                            }
                        }

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

                        if ($remainingAmount > 0 && !($entity instanceof Investor)) {
                            LedgerEntry::create([
                                'tenant_id' => Auth::user()->tenant_id ?? 1,
                                'entity_type' => get_class($entity),
                                'entity_id' => $entity->id,
                                'type' => 'payable',
                                'amount' => $remainingAmount,
                                'original_amount' => $remainingAmount,
                                'paid_amount' => $remainingAmount,
                                'status' => 'paid',
                                'currency_code' => $transactionCurrency,
                                'description' => "$desc (Contado)",
                                'due_date' => now(),
                            ]);
                        }
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
    // 3. INTERÉS COMPUESTO (INTACTO)
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
    // 4. AUXILIARES DE PAGO DE DEUDA (INTACTO)
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

    // CORRECCIÓN: Separamos Inversores (Deuda Capital) de Proveedores (Cupo)
    public function addBalanceToEntity($entity, float $amount, string $currencyCode, ?string $description = 'Recarga')
    {
        return DB::transaction(function () use ($entity, $amount, $currencyCode, $description) {
            
            // SI es Proveedor -> Receivable (Cupo disponible, positivo)
            // SI es Inversor  -> Payable (Deuda de capital, pasivo)
            $type = ($entity instanceof Provider) ? 'receivable' : 'payable';

            return $entity->ledgerEntries()->create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'description' => $description,
                'amount' => $amount,
                'original_amount' => $amount,
                'paid_amount' => 0,
                'type' => $type, // <--- CAMBIO DINÁMICO IMPORTANTE
                'status' => 'pending',
                'due_date' => now(),
                'transaction_type' => get_class($entity),
                'transaction_id' => $entity->id,
                'currency_code' => $currencyCode
            ]);
        });
    }
}