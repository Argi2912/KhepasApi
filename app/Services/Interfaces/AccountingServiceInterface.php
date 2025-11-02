<?php

namespace App\Services\Interfaces;

use App\Models\Transaction;

interface AccountingServiceInterface
{
    /**
     * Registra un asiento contable que involucra cuentas por pagar (CXP) o por cobrar (CXC).
     * @param array $data Los datos de la transacción (user_id, date, description, etc.).
     * @param array $movements Los movimientos contables (array de [account_name, amount, is_debit, is_positive_in_doc]).
     * @param ?int $relatedTransactionId Opcional, para vincular el pago/cobro a una CXC/CXP existente.
     * @return \App\Models\Transaction
     */
    public function registerTransaction(array $data, array $movements, ?int $relatedTransactionId = null): Transaction;
}