<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\BelongsToTenant;

class Investor extends Model
{
    use HasFactory, BelongsToTenant; 

    protected $fillable = [
        'tenant_id', 
        'name', 
        'alias', 
        'email', 
        'phone', 
        'is_active',
        'interest_rate',
        'payout_day',
        'last_interest_date'
    ];

    // 🚨 ESTO FALTABA: 
    // Le dice a Laravel: "Siempre envía 'available_balance' cuando te pidan un Inversionista"
    protected $appends = ['available_balance'];

    // Relación con operaciones de cambio
    public function exchanges()
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    // Relación con la Contabilidad (Ledger)
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    // CÁLCULO DE SALDO REAL (Tu lógica original)
    // Laravel convertirá esto al atributo JSON: "available_balance"
    public function getAvailableBalanceAttribute()
    {
        return $this->ledgerEntries()
            ->where('type', 'payable')
            ->get()
            ->sum(function ($entry) {
                return $entry->original_amount - $entry->paid_amount;
            });
    }
}