<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Broker;
use App\Models\Client;
use App\Models\CurrencyExchange;
use App\Models\ExchangeRate;
use App\Models\InternalTransaction;
use App\Models\Investor;
use App\Models\LedgerEntry;
use App\Models\Platform;
use App\Models\Provider;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    // =========================================================================
    // 0. GENERADOR DE SECUENCIALES (SECURE)
    // =========================================================================
    private function generateSequentialNumber(string $modelClass, string $prefix): string
    {
        // 🔒 LOCK: Bloqueamos la fila más reciente para evitar duplicados en concurrencia
        $latest = $modelClass::lockForUpdate()
            ->withoutGlobalScopes()
            ->withTrashed() // Consideramos eliminados para no repetir histórico
            ->latest('id')
            ->first();
        
        $nextId = $latest ? $latest->id + 1 : 1;
        return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // 1. GESTIÓN DE OPERACIONES DE CAMBIO (CORE)
    // =========================================================================
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // 1. SEGURIDAD INICIAL
            $user = Auth::user();
            $tenantId = $user->tenant_id;
            
            if (!$tenantId) {
                // En producción esto debería ser un log crítico
                throw new Exception("Error de Seguridad: Usuario sin organización (Tenant) asignada.");
            }

            // 2. CÁLCULO DE MONTOS (BACKEND AUTHORITY) 🛡️
            // Ignoramos montos calculados por JS. La verdad está aquí.
            $amountReceived = (float) $data['amount_received'];
            $amountSent     = (float) $data['amount_sent'];

            // Porcentajes (Default 0 si no vienen)
            $pctCharged  = (float) ($data['commission_charged_pct'] ?? 0);
            $pctProvider = (float) ($data['commission_provider_pct'] ?? 0);
            $pctAdmin    = (float) ($data['commission_admin_pct'] ?? 0);
            $pctBroker   = (float) ($data['commission_broker_pct'] ?? 0);

            // Cálculo Matemático Seguro
            $amtCharged  = ($amountReceived * $pctCharged) / 100;   // Ganancia Casa
            $amtProvider = ($amountReceived * $pctProvider) / 100;  // Costo Proveedor
            $amtAdmin    = ($amountReceived * $pctAdmin) / 100;     // Costo Plataforma
            $amtBroker   = ($amountReceived * $pctBroker) / 100;    // Costo Broker

            // Inversionista (si aplica)
            $investorProfitAmount = 0;
            if (($data['capital_type'] ?? 'own') === 'investor') {
                $pctInvestor = (float) ($data['investor_profit_pct'] ?? 0);
                $investorProfitAmount = ($amountReceived * $pctInvestor) / 100;
            }

            // 3. DATOS ADMINISTRATIVOS
            $exchangeNumber = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');
            $userId         = $data['admin_user_id'] ?? $user->id;
            
            // Obtener nombre operador
            $operatorUser = User::find($userId);
            $operatorName = $operatorUser ? $operatorUser->name : 'Usuario Sistema';

            // 4. VALIDACIÓN DE CUENTAS
            $fromAccount    = null;
            $fromAccountId  = null;
            $capitalType    = $data['capital_type'] ?? 'own';
            
            $isDelivered = $data['delivered'] ?? true; 
            $isPaid      = $data['paid'] ?? true;       

            if ($capitalType === 'own') {
                if (empty($data['from_account_id'])) throw new Exception("Se requiere cuenta origen para capital propio.");
                
                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
                $fromAccountId = $fromAccount->id;

                if ($isPaid && $fromAccount->balance < $amountSent) {
                    throw new Exception("Saldo insuficiente en {$fromAccount->name}. (Req: $amountSent)");
                }
            }

            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);

            // 5. CREACIÓN DE LA OPERACIÓN (PERSISTENCIA)
            $exchange = CurrencyExchange::create([
                'tenant_id'       => $tenantId,
                'number'          => $exchangeNumber,
                'type'            => $data['type'] ?? 'exchange',
                'client_id'       => $data['client_id'] ?? null,
                'broker_id'       => $data['broker_id'] ?? null,
                'provider_id'     => $data['provider_id'] ?? null,
                'platform_id'     => $data['platform_id'] ?? null,
                'admin_user_id'   => $userId,
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccount->id,
                
                'amount_sent'     => $amountSent,
                'amount_received' => $amountReceived,
                
                'exchange_rate'   => $data['exchange_rate'],
                'buy_rate'        => $data['buy_rate'] ?? null,
                'received_rate'   => $data['received_rate'] ?? null,
                
                // 🛡️ GUARDAMOS LOS MONTOS CALCULADOS POR NOSOTROS
                'commission_total_amount'    => $amtCharged, // Asumimos que "total" es la ganancia de la casa
                'commission_charged_amount'  => $amtCharged, 
                'commission_provider_amount' => $amtProvider,
                'commission_admin_amount'    => $amtAdmin,
                'commission_broker_amount'   => $amtBroker,
                
                // Guardamos porcentajes para auditoría futura
                'commission_charged_pct'  => $pctCharged,
                'commission_provider_pct' => $pctProvider,
                'commission_admin_pct'    => $pctAdmin,
                'commission_broker_pct'   => $pctBroker,

                'capital_type'           => $capitalType,
                'investor_id'            => $data['investor_id'] ?? null,
                'investor_profit_pct'    => $data['investor_profit_pct'] ?? 0,
                'investor_profit_amount' => $investorProfitAmount,
                
                'reference_id' => $data['reference_id'] ?? null,
                'status'       => $data['status'] ?? 'completed'
            ]);

            // 6. REGISTRAR MOVIMIENTOS DE CAJA (Internal Transactions)

            // --- A. MONEY OUT (Salida de dinero) ---
            if ($capitalType === 'own' && $isPaid && $fromAccount) {
                $fromAccount->decrement('balance', $amountSent);

                InternalTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'account_id' => $fromAccount->id,
                    'source_type' => 'account',
                    'type' => 'expense',
                    'category' => 'Intercambio Enviado',
                    'amount' => $amountSent,
                    'description' => "Salida Op. #{$exchangeNumber}",
                    'transaction_date' => now(),
                    'entity_type' => CurrencyExchange::class,
                    'entity_id' => $exchange->id,
                    'person_name' => $operatorName,
                    'dueño' => $fromAccount->name
                ]);
            }

            // --- B. MONEY IN (Entrada de dinero) ---
            if ($isDelivered) {
                $toAccount->increment('balance', $amountReceived);
                
                InternalTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'account_id' => $toAccount->id,
                    'source_type' => 'account',
                    'type' => 'income',
                    'category' => 'Intercambio Recibido',
                    'amount' => $amountReceived,
                    'description' => "Entrada Op. #{$exchangeNumber}",
                    'transaction_date' => now(),
                    'entity_type' => CurrencyExchange::class,
                    'entity_id' => $exchange->id,
                    'person_name' => $operatorName,
                    'dueño' => $toAccount->name
                ]);
            }

            // 7. AUDITORÍA DE TASA (Anti-Fraude)
            try {
                if ($fromAccount && $toAccount) {
                    $officialRate = ExchangeRate::where('from_currency', $fromAccount->currency_code)
                        ->where('to_currency', $toAccount->currency_code)
                        ->latest()->value('rate');

                    if ($officialRate) {
                        $manualRateUsed = (float) $data['exchange_rate'];
                        $officialRateFloat = (float) $officialRate;
                        // Si la diferencia es mayor a 0.0001, registramos alerta
                        if (abs($manualRateUsed - $officialRateFloat) > 0.0001) {
                            activity()->performedOn($exchange)->causedBy($user)
                                ->withProperties([
                                    'alert_type' => 'manual_rate_override', 
                                    'official_rate' => $officialRateFloat, 
                                    'manual_rate' => $manualRateUsed, 
                                    'difference' => $manualRateUsed - $officialRateFloat, 
                                    'client_name' => $exchange->client->name ?? 'N/A'
                                ])
                                ->event('security_alert')->log('alerta_tasa_modificada');
                        }
                    }
                }
            } catch (Exception $e) {
                // Silenciamos error de auditoría para no bloquear la transacción financiera
                Log::warning("Fallo al auditar tasa: " . $e->getMessage());
            }

            // 8. REGISTRO DE DEUDAS (LEDGER) - Usando valores calculados
            $currencySent = $fromAccount ? $fromAccount->currency_code : '???';
            $currencyReceived = $toAccount->currency_code;

            // Deuda Principal con Cliente
            if (!$isPaid && $capitalType === 'own' && !empty($exchange->client_id)) {
                $this->createLedgerDebt($exchange, $amountSent, $currencySent, 'payable', $exchange->client_id, Client::class, "Por Pagar (Op. {$exchangeNumber})", 'pending');
            }
            if (!$isDelivered && !empty($exchange->client_id)) {
                $this->createLedgerDebt($exchange, $amountReceived, $currencyReceived, 'receivable', $exchange->client_id, Client::class, "Por Cobrar (Op. {$exchangeNumber})", 'pending');
            }

            // Deudas de Comisiones (Usamos variables calculadas $amt...)
            if ($amtProvider > 0 && !empty($data['provider_id']))
                $this->createLedgerDebt($exchange, $amtProvider, 'USD', 'payable', $data['provider_id'], Provider::class, "Comisión Proveedor");
            
            if ($amtBroker > 0 && !empty($data['broker_id']))
                $this->createLedgerDebt($exchange, $amtBroker, 'USD', 'payable', $data['broker_id'], Broker::class, "Comisión Corredor");
            
            if ($amtAdmin > 0 && !empty($data['platform_id']))
                $this->createLedgerDebt($exchange, $amtAdmin, 'USD', 'payable', $data['platform_id'], Platform::class, "Costo Plataforma");
            
            if ($amtCharged > 0 && !empty($exchange->client_id)) {
                $commStatus = $isDelivered ? 'paid' : 'pending';
                // Asumimos que la comisión de casa se cobra en USD o en la moneda recibida
                // Si la moneda recibida no es USD, ojo con esto. Por defecto dejo USD según tu código original.
                $commCurrency = 'USD'; 
                $this->createLedgerDebt($exchange, $amtCharged, $commCurrency, 'receivable', $exchange->client_id, Client::class, "Comisión de Casa", $commStatus);
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
    // 2. GESTIÓN DE MOVIMIENTOS INTERNOS
    // =========================================================================
    public function createInternalTransaction(array $data)
    {
        return DB::transaction(function () use ($data) {
            $sourceType = $data['source_type'] ?? 'account';
            
            // 🔥 CORRECCIÓN: Aseguramos que el monto sea siempre positivo
            $amount     = abs((float) $data['amount']); 
            
            $type       = $data['type']; 
            $transactionCurrency = 'USD';

            // Nombre Usuario para Caja
            $personName = null;
            if (isset($data['user_id'])) {
                $u = User::find($data['user_id']);
                if($u) $personName = $u->name;
            }

            // GESTIÓN BANCO (CAJA)
            if ($sourceType === 'account') {
                $account = Account::lockForUpdate()->find($data['account_id']);
                if (!$account) throw new Exception("Cuenta no encontrada.");
                $transactionCurrency = $account->currency_code;

                if ($type === 'expense') {
                    if ($account->balance < $amount) throw new Exception("Saldo insuficiente en Banco.");
                    $account->decrement('balance', $amount);
                } else {
                    $account->increment('balance', $amount);
                }
            }

            // GESTIÓN ENTIDADES (Proveedor/Inversionista/Cliente)
            $entity = null;
            $entityType = $data['entity_type'] ?? null;
            $entityId   = $data['entity_id'] ?? null;

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

            if ($entity) {
                $remainingAmount = $amount;
                $desc = $data['description'] ?? 'Movimiento Interno';
                $tenantId = Auth::user()->tenant_id ?? 1;

                // --- INCOME (Entra dinero a tu caja) ---
                if ($type === 'income') {
                    if ($entity instanceof Investor) {
                        // Inversionista APORTA capital -> Sube su saldo
                        $entity->increment('available_balance', $amount);
                        
                        // Creamos deuda Payable (Capital)
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'entity_type' => get_class($entity), 'entity_id' => $entity->id,
                            'type' => 'payable', 'amount' => $amount, 'original_amount' => $amount, 'paid_amount' => 0,
                            'status' => 'pending', 'currency_code' => $transactionCurrency, 
                            'description' => "$desc (Aporte Capital)", 'due_date' => now(),
                        ]);
                    }
                    elseif ($entity instanceof Provider) {
                        // Proveedor -> Caja. RETIRO DE SU BILLETERA.
                        if ($entity->available_balance < $amount) {
                            throw new Exception("El proveedor no tiene saldo suficiente (Disponible: {$entity->available_balance}).");
                        }
                        $entity->decrement('available_balance', $amount);

                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'entity_type' => get_class($entity), 'entity_id' => $entity->id,
                            'type' => 'payable', 'amount' => $amount, 'original_amount' => $amount, 'paid_amount' => 0,
                            'status' => 'pending', 'currency_code' => $transactionCurrency, 
                            'description' => "$desc (Movido a Caja)", 'due_date' => now(),
                        ]);
                    }
                    else {
                        // Clientes (Cobranzas)
                        $ledgers = LedgerEntry::where('entity_type', get_class($entity))
                            ->where('entity_id', $entity->id)->where('type', 'receivable')
                            ->where('status', '!=', 'paid')->orderBy('created_at', 'asc')->lockForUpdate()->get();

                        foreach ($ledgers as $ledger) {
                            if ($remainingAmount <= 0) break;
                            $pending = $ledger->amount - $ledger->paid_amount;
                            if ($pending <= $remainingAmount) { $ledger->paid_amount += $pending; $ledger->status = 'paid'; $remainingAmount -= $pending; } 
                            else { $ledger->paid_amount += $remainingAmount; $ledger->status = 'partially_paid'; $remainingAmount = 0; }
                            $ledger->save();
                        }
                        if ($remainingAmount > 0) {
                            $balanceType = ($entity instanceof Provider) ? 'payable' : 'receivable'; 
                            LedgerEntry::create([
                                'tenant_id' => $tenantId,
                                'entity_type' => get_class($entity), 'entity_id' => $entity->id,
                                'type' => $balanceType, 'amount' => $remainingAmount, 'original_amount' => $remainingAmount,
                                'paid_amount' => ($balanceType === 'receivable' ? $remainingAmount : 0), 
                                'status' => ($balanceType === 'receivable' ? 'paid' : 'pending'),
                                'currency_code' => $transactionCurrency, 'description' => "$desc (Contado)", 'due_date' => now(),
                            ]);
                        }
                    }
                }

                // --- EXPENSE (Sale dinero de tu caja) ---
                if ($type === 'expense') {
                    if ($entity instanceof Provider) {
                        // De Caja -> Proveedor. Aumenta su disponible.
                        $entity->increment('available_balance', $amount);
                    }
                    elseif ($entity instanceof Investor) {
                        // De Caja -> Inversionista (Retiro de Capital). Baja su saldo.
                        if ($entity->available_balance < $amount) {
                            throw new Exception("Saldo insuficiente en Inversionista ({$entity->available_balance}).");
                        }
                        $entity->decrement('available_balance', $amount);
                    }
                    else {
                        // Pagos a Terceros
                        $ledgers = LedgerEntry::where('entity_type', get_class($entity))
                            ->where('entity_id', $entity->id)->where('type', 'payable')
                            ->where('status', '!=', 'paid')->orderBy('created_at', 'asc')->lockForUpdate()->get();

                        if ($entity instanceof Investor) {
                            $totalDebt = $ledgers->sum(fn($l) => $l->amount - $l->paid_amount);
                            if ($totalDebt < $amount) throw new Exception("Saldo insuficiente. Disponible: $totalDebt");
                        }

                        foreach ($ledgers as $ledger) {
                            if ($remainingAmount <= 0) break;
                            $pending = $ledger->amount - $ledger->paid_amount;
                            if ($pending <= $remainingAmount) { $ledger->paid_amount += $pending; $ledger->status = 'paid'; $remainingAmount -= $pending; } 
                            else { $ledger->paid_amount += $remainingAmount; $ledger->status = 'partially_paid'; $remainingAmount = 0; }
                            $ledger->save();
                        }

                        if ($remainingAmount > 0 && !($entity instanceof Investor) && !($entity instanceof Provider)) {
                            LedgerEntry::create([
                                'tenant_id' => $tenantId,
                                'entity_type' => get_class($entity), 'entity_id' => $entity->id,
                                'type' => 'payable', 'amount' => $remainingAmount, 'original_amount' => $remainingAmount,
                                'paid_amount' => $remainingAmount, 'status' => 'paid',
                                'currency_code' => $transactionCurrency, 'description' => "$desc (Contado)", 'due_date' => now(),
                            ]);
                        }
                    }
                }
            }

            // Transferencias entre cuentas propias
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

            $dbAccountId = ($sourceType === 'account') ? $data['account_id'] : null;

            $transaction = InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1,
                'user_id' => $data['user_id'],
                'account_id' => $dbAccountId,
                'source_type' => $sourceType,
                'type' => $type,
                'category' => $data['category'],
                'amount' => $amount, // 🔥 SIEMPRE POSITIVO
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'dueño' => $data['dueño'] ?? null,
                'person_name' => $personName,
                'entity_type' => $entityType,
                'entity_id'   => $entityId
            ]);

            return $transaction;
        });
    }

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
                'source_type' => 'account',
                'type' => $txType,
                'category' => $cat,
                'amount' => $entry->amount,
                'description' => "Pago/Cobro #{$entry->id}: {$entry->description}",
                'transaction_date' => now(),
                'dueño' => $account->name,
                'person_name' => $entry->entity ? ($entry->entity->name ?? 'Tercero') : 'Desconocido'
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
                'source_type' => 'account',
                'type' => $txType,
                'category' => $cat,
                'amount' => $amount,
                'description' => $description ?? "Abono Ledger",
                'transaction_date' => now(),
                'dueño' => $account->name,
                'person_name' => $entry->entity ? ($entry->entity->name ?? 'Tercero') : 'Desconocido'
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

    // ⬇️ RECARGA DE BILLETERA (Logic Providers) ⬇️
    public function addBalanceToEntity($entity, float $amount, string $currencyCode, ?string $description = 'Recarga')
    {
        return DB::transaction(function () use ($entity, $amount, $currencyCode, $description) {
            
            $className = get_class($entity);
            $tenantId = Auth::user()->tenant_id ?? 1;

            if ($entity instanceof \App\Models\Provider || $className === 'App\Models\Provider') {
                // 1. Incrementar saldo del proveedor
                $entity->increment('available_balance', $amount);

                // 2. Crear entrada en Ledger como "Por Pagar" al proveedor
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'entity_type' => $className,
                    'entity_id' => $entity->id,
                    'type' => 'payable',
                    'amount' => $amount,
                    'original_amount' => $amount,
                    'paid_amount' => 0,
                    'status' => 'pending',
                    'currency_code' => $currencyCode,
                    'description' => $description ?? 'Por Pagar a Proveedor',
                    'due_date' => now(),
                ]);

                return $entity;
            }

            // Si no es proveedor, lógica de Ledger normal
            return $entity->ledgerEntries()->create([
                'tenant_id' => $tenantId,
                'description' => $description,
                'amount' => $amount,
                'original_amount' => $amount,
                'paid_amount' => 0,
                'type' => 'payable',
                'status' => 'pending',
                'due_date' => now(),
                'transaction_type' => $className,
                'transaction_id' => $entity->id,
                'currency_code' => $currencyCode
            ]);
        });
    }
}