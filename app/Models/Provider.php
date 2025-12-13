<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR TRAIT
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany; // <--- NUEVO: Importar MorphMany
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
     * Relación con el libro contable (Historial de saldos/deudas).
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    /**
     * Atributo virtual para obtener el saldo disponible actual.
     * Uso: $provider->available_balance
     * @return float
     */
 public function getAvailableBalanceAttribute()
    {
        // Usamos la colección en memoria para asegurar que se usen los valores frescos
        // o una consulta directa que reste las columnas.
        
        return $this->ledgerEntries()
            ->where('type', 'payable') // Solo lo que se le debe al proveedor
            ->get() // Traemos los registros
            ->sum(function ($entry) {
                // Usamos el cálculo: Monto Original - Monto Pagado
                return $entry->original_amount - $entry->paid_amount;
            });
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