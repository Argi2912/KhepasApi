<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeTransaction extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'exchange_rate_id', 'currency_given_id', 'currency_received_id', 'amount_given', 'amount_received', 'fee'];

    // La transacción contable asociada
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    // La tasa de cambio usada en la operación
    public function exchangeRate()
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    // Divisa que se entregó
    public function currencyGiven()
    {
        return $this->belongsTo(Currency::class, 'currency_given_id');
    }
    
    // Divisa que se recibió
    public function currencyReceived()
    {
        return $this->belongsTo(Currency::class, 'currency_received_id');
    }
}
