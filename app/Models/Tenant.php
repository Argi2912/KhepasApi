<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
        'binance_merchant_trade_no',
        'binance_prepay_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Un Tenant tiene muchos Usuarios (Admins, Corredores, etc.)
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
    
    // Aquí puedes añadir las otras relaciones 'hasMany' si las necesitas
    // para consultas a nivel de Superadmin (ej. $tenant->clients)

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function transactionRequests(): HasMany
    {
        return $this->hasMany(TransactionRequest::class);
    }

    /**
     * Movimientos internos de caja
     */
    public function internalTransactions(): HasMany
    {
        return $this->hasMany(InternalTransaction::class);
    }

    /**
     * Intercambios de divisa
     */
    public function currencyExchanges(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    /**
     * Plataformas del tenant
     */
    public function platforms(): HasMany
    {
        return $this->hasMany(Platform::class);
    }
}
