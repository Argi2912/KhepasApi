<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR TRAIT
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder; // <-- 2. IMPORTAR BUILDER

class Provider extends Model
{
   use HasFactory, BelongsToTenant, Filterable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'contact_person',
        'email',
        'phone',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function currencyExchanges(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dollarPurchases(): HasMany
    {
        return $this->hasMany(DollarPurchase::class);
    }

    /**
     * Filtra por un término de búsqueda (nombre o persona de contacto).
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(Builder $query, $term): Builder
    {
        $term = "%{$term}%";
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', $term)
              ->orWhere('contact_person', 'like', $term);
        });
    }
}
