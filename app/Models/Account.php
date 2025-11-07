<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder; // <-- 2. IMPORTAR

class Account extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'currency_code',
        'balance',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => 'decimal:2',
    ];

    // --- Relaciones Inversas (Esta cuenta como origen/destino) ---

    /**
     * Transacciones donde esta cuenta fue el 'Origen'
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function currencyExchangesFrom(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class, 'from_account_id');
    }

    /**
     * Transacciones donde esta cuenta fue el 'Destino'
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function currencyExchangesTo(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class, 'to_account_id');
    }

    /**
     * Transacciones donde esta cuenta fue la 'Plataforma'
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dollarPurchasesPlatform(): HasMany
    {
        return $this->hasMany(DollarPurchase::class, 'platform_account_id');
    }

    public function scopeCurrencyCode(Builder $query, $code): Builder
    {
        return $query->where('currency_code', $code);
    }
}
