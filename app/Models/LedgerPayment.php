<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerPayment extends Model
{
    protected $fillable = [
        'ledger_entry_id',
        'account_id',
        'user_id',
        'amount',
        'currency_type',
        'description',
        'payment_date'
    ];

    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCurrencyNameAttribute()
    {
        return \App\Models\Currency::find($this->currency_type)?->name ?? $this->currency_type;
    }
}
