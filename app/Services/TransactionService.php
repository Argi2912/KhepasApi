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
                $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
                if ($fromAccount->balance < $amountSent) throw new Exception("Saldo insuficiente.");
                $fromAccount->decrement('balance', $amountSent);
                $fromAccountId = $fromAccount->id;
                InternalTransaction::create(['tenant_id' => $tenantId, 'user_id' => $data['admin_user_id'], 'account_id' => $fromAccount->id, 'type' => 'expense', 'category' => 'Intercambio Enviado', 'amount' => $amountSent, 'description' => "Salida Intercambio {$exchangeNumber}", 'transaction_date' => now()]);
            }

            // Money In
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            if ($isPaidNow) {
                $toAccount->increment('balance', $amountReceived);
                InternalTransaction::create(['tenant_id' => $tenantId, 'user_id' => $data['admin_user_id'], 'account_id' => $toAccount->id, 'type' => 'income', 'category' => 'Intercambio Recibido', 'amount' => $amountReceived, 'description' => "Entrada Intercambio {$exchangeNumber}", 'transaction_date' => now()]);
            }

            // Registro Exchange
            $exchange = CurrencyExchange::create([
                'tenant_id' => $tenantId, 'number' => $exchangeNumber, 'type' => $data['type'] ?? 'exchange', 'client_id' => $data['client_id'] ?? null, 'broker_id' => $data['broker_id'] ?? null, 'provider_id' => $data['provider_id'] ?? null, 'platform_id' => $data['platform_id'] ?? null, 'admin_user_id' => $data['admin_user_id'], 'from_account_id' => $fromAccountId, 'to_account_id' => $toAccount->id, 'amount_sent' => $amountSent, 'amount_received' => $amountReceived, 'exchange_rate' => $data['exchange_rate'], 'buy_rate' => $data['buy_rate'] ?? null, 'received_rate' => $data['received_rate'] ?? null, 'commission_total_amount' => $data['commission_total_amount'] ?? 0, 'commission_provider_amount' => $data['commission_provider_amount'] ?? 0, 'commission_admin_amount' => $data['commission_admin_amount'] ?? 0, 'capital_type' => $capitalType, 'investor_id' => $data['investor_id'] ?? null, 'investor_profit_pct' => $data['investor_profit_pct'] ?? 0, 'investor_profit_amount' => $data['investor_profit_amount'] ?? 0, 'reference_id' => $data['reference_id'] ?? null, 'status' => $statusRaw
            ]);

            // COMISIONES (Simplificado con helper)
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

            if (!$isPaidNow && !empty($exchange->client_id) && $amountReceived > 0) {
                $this->createLedgerDebt($exchange, $amountReceived, $currencyIn, 'receivable', $exchange->client_id, Client::class, "Operación de Cambio #{$exchange->number}", 'pending');
            }

            return $exchange->load('client');
        });
    }

    private function createLedgerDebt($exchange, $amount, $currency, $type, $entityId, $entityType, $desc, $status = 'pending') {
        $exchange->ledgerEntries()->create([
            'tenant_id' => $exchange->tenant_id, 'description' => "$desc (Op. #{$exchange->number})", 'amount' => $amount, 'original_amount' => $amount, 'paid_amount' => ($status == 'paid' ? $amount : 0), 'currency_code' => $currency, 'type' => $type, 'status' => $status, 'entity_id' => $entityId, 'entity_type' => $entityType, 'transaction_type' => CurrencyExchange::class, 'transaction_id' => $exchange->id, 'due_date' => now()
        ]);
    }

    // =========================================================================
    // 2. GESTIÓN DE MOVIMIENTOS INTERNOS (Caja, Inversionistas, Retiros)
    // =========================================================================
    public function createInternalTransaction(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // Historial
            $transaction = InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1, 'user_id' => $data['user_id'], 'account_id' => $data['account_id'], 'source_type' => $data['source_type'] ?? 'account', 'type' => $data['type'], 'category' => $data['category'], 'amount' => $data['amount'], 'description' => $data['description'] ?? null, 'transaction_date' => $data['transaction_date'] ?? now(), 'dueño' => $data['dueño'] ?? null, 'person_name' => $data['person_name'] ?? null, 'entity_type' => $data['entity_type'] ?? null, 'entity_id' => $data['entity_id'] ?? null
            ]);

            $sourceType = $data['source_type'] ?? 'account';
            $amount     = (float) $data['amount'];

            // A. INGRESO DE DINERO (Inversión Manual)
            // Sube Capital Base (available_balance) Y Sube Disponible (Ledger)
            if ($sourceType === 'investor' && $data['type'] === 'income') {
                $investor = Investor::lockForUpdate()->find($data['account_id']);
                if ($investor) {
                    // 🔥 Aquí usamos el nombre real de tu columna
                    $investor->increment('available_balance', $amount);
                    
                    LedgerEntry::create([
                        'tenant_id' => Auth::user()->tenant_id ?? 1, 'entity_type' => Investor::class, 'entity_id' => $investor->id, 'type' => 'payable', 'amount' => $amount, 'original_amount' => $amount, 'paid_amount' => 0, 'status' => 'pending', 'description' => 'Inyección de Capital', 'due_date' => now()
                    ]);
                }
            }

            // B. SALIDA DE DINERO (Mover a Banco)
            // Baja Disponible (Ledger) PERO Mantiene Capital Base (available_balance)
            if ($sourceType === 'investor' && $data['type'] === 'expense') {
                $investorId = $data['account_id'];
                
                $ledgers = LedgerEntry::where('entity_type', Investor::class)
                    ->where('entity_id', $investorId)->where('type', 'payable')->where('status', '!=', 'paid')
                    ->orderBy('created_at', 'asc')->get();

                $totalAvailable = $ledgers->sum(fn($l) => $l->amount - $l->paid_amount);
                if ($totalAvailable < $amount) {
                    throw new Exception("Disponible insuficiente para mover ($totalAvailable).");
                }

                $remaining = $amount;
                foreach ($ledgers as $ledger) {
                    if ($remaining <= 0) break;
                    $pending = $ledger->amount - $ledger->paid_amount;
                    if ($pending <= $remaining) {
                        $ledger->paid_amount += $pending;
                        $ledger->status = 'paid';
                        $remaining -= $pending;
                    } else {
                        $ledger->paid_amount += $remaining;
                        $ledger->status = 'partial';
                        $remaining = 0;
                    }
                    $ledger->save();
                }
                // NOTA: NO tocamos 'available_balance', la deuda se mantiene.
            }

            // C. IMPACTO EN BANCO DESTINO
            if ($data['type'] === 'expense' && ($data['entity_type'] ?? '') === 'App\Models\Account') {
                $bankAccount = Account::find($data['entity_id']);
                if ($bankAccount) $bankAccount->increment('balance', $amount);
                
                InternalTransaction::create([
                    'tenant_id' => Auth::user()->tenant_id ?? 1, 'user_id' => $data['user_id'], 'account_id' => $data['entity_id'], 'source_type' => 'account', 'type' => 'income', 'category' => 'Transferencia Recibida', 'amount' => $amount, 'description' => "Recibido de Inversionista: " . ($data['person_name'] ?? ''), 'transaction_date' => $data['transaction_date'] ?? now(), 'entity_type' => Investor::class, 'entity_id' => $data['account_id']
                ]);
            }

            return $transaction;
        });
    }

    // =========================================================================
    // 3. INTERÉS COMPUESTO (SOLO CAPITAL BASE)
    // =========================================================================
    public function applyCompoundInterest(int $investorId, float $amount, string $description = 'Interés Compuesto')
    {
        return DB::transaction(function () use ($investorId, $amount, $description) {
            $investor = Investor::lockForUpdate()->findOrFail($investorId);

            // 1. SUBE EL CAPITAL BASE (Tu deuda total aumenta)
            // 🔥 Aquí usamos el nombre real de tu columna
            $investor->increment('available_balance', $amount);

            // 2. NO CREA LEDGER (El dinero no está disponible para mover, se reinvirtió)

            // 3. Historial
            InternalTransaction::create([
                'tenant_id' => Auth::user()->tenant_id ?? 1, 'user_id' => Auth::id(), 'account_id' => $investorId, 'source_type' => 'investor', 'type' => 'info', 'category' => 'Interés Compuesto', 'amount' => $amount, 'description' => $description, 'transaction_date' => now(), 'entity_type' => Investor::class, 'entity_id' => $investorId
            ]);
        });
    }

    // =========================================================================
    // 4. AUXILIARES DE PAGO DE DEUDA (Ledger)
    // =========================================================================
    public function processDebtPayment(LedgerEntry $entry, int $accountId)
    {
        return DB::transaction(function () use ($entry, $accountId) {
            if ($entry->status === 'paid') throw new Exception("Ya procesado.");
            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $entry->amount) throw new Exception("Saldo insuficiente.");
                $account->decrement('balance', $entry->amount);
                $txType = 'expense'; $cat = 'Pago de Deuda';
            } else {
                $account->increment('balance', $entry->amount);
                $txType = 'income'; $cat = 'Cobro de Deuda';
            }

            $tx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id, 'user_id' => Auth::id(), 'account_id' => $account->id, 'type' => $txType, 'category' => $cat, 'amount' => $entry->amount, 'description' => "Pago/Cobro #{$entry->id}: {$entry->description}", 'transaction_date' => now()
            ]);
            $entry->update(['status' => 'paid']);
            return $tx;
        });
    }

    public function processLedgerPayment(LedgerEntry $entry, int $accountId, float $amount, ?string $description = null)
    {
        return DB::transaction(function () use ($entry, $accountId, $amount, $description) {
            $account = Account::lockForUpdate()->findOrFail($accountId);

            if ($entry->type === 'payable') {
                if ($account->balance < $amount) throw new Exception("Saldo insuficiente.");
                $account->decrement('balance', $amount);
                $txType = 'expense'; $cat = 'Pago de Deuda';
            } else {
                $account->increment('balance', $amount);
                $txType = 'income'; $cat = 'Cobro de Crédito';
            }

            $tx = InternalTransaction::create([
                'tenant_id' => $entry->tenant_id, 'user_id' => Auth::id(), 'account_id' => $accountId, 'type' => $txType, 'category' => $cat, 'amount' => $amount, 'description' => $description ?? "Abono Ledger", 'transaction_date' => now()
            ]);

            $entry->payments()->create([
                'account_id' => $accountId, 'user_id' => Auth::id(), 'amount' => $amount, 'description' => $description, 'payment_date' => now()
            ]);

            $entry->increment('paid_amount', $amount);
            if ($entry->paid_amount >= $entry->amount) $entry->update(['status' => 'paid']);
            else $entry->update(['status' => 'partial']);

            return $tx;
        });
    }

    public function addBalanceToEntity($entity, float $amount, ?string $description = 'Recarga')
    {
        return DB::transaction(function () use ($entity, $amount, $description) {
            return $entity->ledgerEntries()->create([
                'tenant_id' => Auth::user()->tenant_id ?? 1, 'description' => $description, 'amount' => $amount, 'original_amount' => $amount, 'paid_amount' => 0, 'type' => 'payable', 'status' => 'pending', 'due_date' => now(), 'transaction_type' => get_class($entity), 'transaction_id' => $entity->id
            ]);
        });
    }
}