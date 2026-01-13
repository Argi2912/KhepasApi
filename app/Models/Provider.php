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

    protected $fillable = ['tenant_id', 'name', 'contact_person', 'email', 'phone'];
    protected $appends = ['current_balance', 'available_balance'];

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    public function transactions()
    {
        return $this->hasMany(InternalTransaction::class, 'account_id')
            ->where('source_type', 'provider');
    }

    // --- LÓGICA DE NEGOCIO CORREGIDA ---

    /**
     * DEUDA TOTAL (Current Balance)
     * Suma de todas las 'Cuentas por Pagar' (Payables) generadas por tus retiros.
     * Resta lo que ya hayas pagado (Pagos a deuda).
     */
    public function getCurrentBalanceAttribute()
    {
        // Sumamos lo pendiente en Ledgers de tipo 'payable'
        return $this->ledgerEntries()
            ->where('type', 'payable')
            ->get()
            ->sum(function($entry) {
                return $entry->amount - $entry->paid_amount;
            });
    }

    /**
     * SALDO DISPONIBLE (Available Balance)
     * Es tu Cupo Total ('receivable') menos tu Deuda Actual.
     */
    public function getAvailableBalanceAttribute()
    {
        // 1. Cupo Total (Cargas de Saldo) -> Ahora se guardan como 'receivable'
        $cupoTotal = $this->ledgerEntries()
            ->where('type', 'receivable')
            ->sum('original_amount');

        // 2. Deuda Actual
        $deuda = $this->current_balance;

        return $cupoTotal - $deuda;
    }
    
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