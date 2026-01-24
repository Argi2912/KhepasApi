<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use App\Models\InternalTransaction;

class Provider extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    // 1. AGREGA 'available_balance' AQUÍ
    protected $fillable = [
        'tenant_id', 
        'name', 
        'contact_person', 
        'email', 
        'phone', 
        'available_balance', // <--- IMPORTANTE: Permite guardar el saldo de la billetera
        'is_active'
    ];

    // 2. QUITA 'available_balance' DE AQUÍ (Ya es una columna real, no calculada)
    protected $appends = ['current_balance'];

    protected $casts = [
        'available_balance' => 'float', // Asegura que siempre se lea como número
        'is_active' => 'boolean'
    ];

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    public function transactions()
    {
        return $this->hasMany(InternalTransaction::class, 'account_id')
            ->where('source_type', 'provider');
    }

    // --- LÓGICA DE NEGOCIO ---

    /**
     * DEUDA TOTAL (Current Balance)
     * Esto sigue igual: Suma cuánto le debemos realmente (dinero que ya movimos a nuestro banco).
     */
    public function getCurrentBalanceAttribute()
    {
        // Sumamos lo pendiente en Ledgers de tipo 'payable' (Deuda real)
        return $this->ledgerEntries()
            ->where('type', 'payable')
            ->where('status', '!=', 'paid') // Solo sumamos lo que no está pagado
            ->get()
            ->sum(function($entry) {
                return $entry->amount - $entry->paid_amount;
            });
    }

    /**
     * ❌ ELIMINADO: getAvailableBalanceAttribute
     * * Razón: Ahora 'available_balance' es una columna real en la base de datos.
     * Si dejabas esta función, el sistema ignoraba la columna y trataba de calcular 
     * el saldo sumando facturas viejas, lo cual daba error o negativo.
     * * Ahora Laravel leerá directamente la columna 'available_balance'.
     */
    
    // ... (Resto de relaciones y scopes sin cambios) ...
    public function currencyExchanges(): HasMany { return $this->hasMany(CurrencyExchange::class); }
    public function dollarPurchases(): HasMany { return $this->hasMany(DollarPurchase::class); }
    
    public function scopeSearch(Builder $query, $term): Builder
    {
        $term = "%{$term}%";
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', $term)->orWhere('contact_person', 'like', $term);
        });
    }
}