<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelatedAccount extends Model
{
    use HasFactory;

    protected $fillable = ['main_transaction_id', 'related_transaction_id', 'account_to_affect_id'];

    // La transacción principal (ej: el pago)
    public function mainTransaction()
    {
        return $this->belongsTo(Transaction::class, 'main_transaction_id');
    }

    // La transacción relacionada (ej: la CXC o CXP original)
    public function relatedTransaction()
    {
        return $this->belongsTo(Transaction::class, 'related_transaction_id');
    }
    
    // La cuenta contable maestra afectada (CXP o CXC)
    public function accountToAffect()
    {
        return $this->belongsTo(Account::class, 'account_to_affect_id');
    }
}
