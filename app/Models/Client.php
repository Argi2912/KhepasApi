<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Client extends Model
{
    use HasFactory, BelongsToTenant, Filterable; // <-- IMPORTANTE

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id', // El trait lo gestiona, pero debe estar en fillable
        'name',
        'email',
        'phone',
        'details',
    ];

    /**
     * Un Cliente ha realizado muchas transacciones de "Cambio de Divisas"
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function currencyExchanges(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    /**
     * Un Cliente ha realizado muchas transacciones de "Compra de Dólares"
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dollarPurchases(): HasMany
    {
        return $this->hasMany(DollarPurchase::class);
    }

    // --- LOCAL SCOPES (FILTROS) ---

    /**
     * Filtra por un término de búsqueda (nombre, email, o teléfono).
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(Builder $query, $term): Builder
    {
        $term = "%{$term}%";
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', $term)
              ->orWhere('email', 'like', $term)
              ->orWhere('phone', 'like', $term);
        });
    }
}
