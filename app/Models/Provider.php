<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use App\Models\InternalTransaction; // <--- 1. IMPORTANTE: Agregar esto

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

    // 2. AGREGAMOS LOS ATTRIBUTES PARA QUE VUE LOS RECIBA
    protected $appends = ['current_balance', 'available_balance'];

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
     * Relación con el libro contable (Cuentas por Pagar).
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    // 3. NUEVA RELACIÓN: Historial de movimientos (Ingresos y Retiros)
    public function transactions()
    {
        return $this->hasMany(InternalTransaction::class, 'account_id');
    }

    // 4. SALDO BASE (Lo que está en Cuentas por Pagar)
    // Este monto se mantiene FIJO aunque muevas el dinero a tus bancos.
    public function getCurrentBalanceAttribute()
    {
        // Sumamos lo que dice el Ledger (Cuentas por Pagar)
        $ledgerSum = $this->ledgerEntries()
            ->where('type', 'payable')
            ->sum('original_amount');

        // (Fallback) Si es 0, miramos si hay ingresos manuales en el historial
        // por si acaso es un proveedor viejo o con datos migrados.
        if ($ledgerSum == 0) {
            return $this->transactions()
                ->where('type', 'income')
                ->where(function($q) {
                    $q->where('source_type', 'provider')->orWhere('source_type', 'account');
                })
                ->sum('amount');
        }

        return $ledgerSum;
    }

    // 5. DISPONIBLE PARA MOVER (Liquidez Real)
    // Fórmula: (Saldo Base) - (Total Retirado/Transferido)
    public function getAvailableBalanceAttribute()
    {
        // A. Obtenemos el Saldo Base (Deuda Total)
        $totalIngresos = $this->current_balance;

        // B. Sumamos todo lo que has SACADO (Transferencias a tus cuentas)
        // Buscamos transacciones de tipo 'expense' (gastos/salidas)
        $totalRetiros = $this->transactions()
            ->where('type', 'expense')
            ->where(function($q) {
                // Buscamos tanto con etiqueta correcta 'provider' como la vieja 'account'
                $q->where('source_type', 'provider')->orWhere('source_type', 'account');
            })
            ->sum('amount');

        // C. Resultado: Lo que tenías MENOS lo que sacaste
        return $totalIngresos - $totalRetiros;
    }

    /**
     * Filtra por un término de búsqueda.
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