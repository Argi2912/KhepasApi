<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeTransaction extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'exchange_rate_id', 'currency_given_id', 'currency_received_id', 'customer_user_id','provider_user_id','broker_user_id','amount_given', 'net_amount_converted','amount_received', 'effective_rate','commission_provider_percentage','commission_provider_amount','commission_platform_percentage','commission_platform_amount','commission_company_percentage','commission_company_amount','total_commission_expense_amount','fee'];

    protected $casts = [
        'amount_given' => 'decimal:4',
        'net_amount_converted' => 'decimal:4',
        'amount_received' => 'decimal:4',
        'effective_rate' => 'decimal:6',
        'commission_provider_percentage' => 'decimal:4',
        'commission_provider_amount' => 'decimal:4',
        'commission_platform_percentage' => 'decimal:4',
        'commission_platform_amount' => 'decimal:4',
        'commission_company_percentage' => 'decimal:4',
        'commission_company_amount' => 'decimal:4',
        'total_commission_expense_amount' => 'decimal:4',
    ];


    // --- RELACIONES ---

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function exchangeRate()
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function currencyGiven()
    {
        return $this->belongsTo(Currency::class, 'currency_given_id');
    }

    public function currencyReceived()
    {
        return $this->belongsTo(Currency::class, 'currency_received_id');
    }

    // --- ACTORES ---

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }
    
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }
    
    public function broker()
    {
        return $this->belongsTo(User::class, 'broker_user_id');
    }
}