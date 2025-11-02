<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Account;
use App\Services\Interfaces\AccountingServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingService implements AccountingServiceInterface
{
    /**
     * @inheritDoc
     */
    public function registerTransaction(array $data, array $movements, ?int $relatedTransactionId = null): Transaction
    {
        // 1. VALIDACIÓN INICIAL: Asegura que el asiento esté balanceado (Débito = Crédito).
        $totalDebit = array_sum(array_column(array_filter($movements, fn($m) => $m['is_debit'] === true), 'amount'));
        $totalCredit = array_sum(array_column(array_filter($movements, fn($m) => $m['is_debit'] === false), 'amount'));

        if (abs($totalDebit - $totalCredit) > 0.001) {
             throw new \Exception("El asiento contable no está balanceado: Débito ($totalDebit) != Crédito ($totalCredit)");
        }
        
        return DB::transaction(function () use ($data, $movements, $relatedTransactionId) {
            // Generar código de referencia
            $data['reference_code'] = $data['reference_code'] ?? Str::uuid()->toString();
            
            // 2. Crear la Transacción (Asiento Principal)
            $transaction = Transaction::create($data);

            // 3. Crear los Detalles de la Transacción (Partidas)
            foreach ($movements as $movement) {
                // Buscar la Cuenta por su nombre y tenant
                $account = Account::where('name', $movement['account_name'])
                                  ->where('tenant_id', $transaction->tenant_id)
                                  ->firstOrFail();
                
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $account->id,
                    'amount' => abs($movement['amount']), // Siempre positivo en la base de datos
                    'is_debit' => $movement['is_debit'],
                ]);
            }
            
            // 4. Registrar la Relación (si es un pago/cobro de una CXP/CXC)
            if ($relatedTransactionId) {
                $accountToAffect = Account::where('tenant_id', $transaction->tenant_id)
                                          ->where('type', $movement['account_type']) // Necesitas pasar el tipo (CXP o CXC)
                                          ->firstOrFail();
                                          
                $transaction->relatedAccounts()->create([
                    'related_transaction_id' => $relatedTransactionId,
                    'account_to_affect_id' => $accountToAffect->id,
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Helper para determinar si un movimiento es Débito o Crédito basado en el tipo de cuenta.
     * @param string $accountType Tipo de cuenta (CASH, CXC, CXP, INGRESS, EGRESS)
     * @param bool $isPositive Si el monto en el documento original era POSITIVO
     * @return bool is_debit
     */
    public function determineDebitCredit(string $accountType, bool $isPositive): bool
    {
        // Reglas de la Contabilidad:
        // Cuentas de Activo (CASH, CXC): Aumentan con Débito (TRUE), Disminuyen con Crédito (FALSE)
        // Cuentas de Pasivo/Patrimonio/Ingreso (CXP, INGRESS): Aumentan con Crédito (FALSE), Disminuyen con Débito (TRUE)
        
        return match ($accountType) {
            'CASH', 'CXC' => $isPositive, // POSITIVO significa AUMENTO (Débito)
            'CXP', 'INGRESS' => !$isPositive, // POSITIVO significa DISMINUCIÓN (Débito, si el pasivo disminuye)
            'EGRESS' => $isPositive, // EGRESO es un Activo/Gasto que aumenta con Débito
            default => throw new \InvalidArgumentException("Tipo de cuenta no reconocido: $accountType"),
        };
    }
}