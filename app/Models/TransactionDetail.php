<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;

    protected $fillable = [ 'transaction_id',  'account_id',  'amount',  'is_debit' ];

    // Relación N:1 con Transaction
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    // Relación N:1 con Account (la cuenta afectada: CXP, CXC, Caja, Ingreso, etc.)
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
