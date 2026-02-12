<?php

namespace App\Models;

use App\Models\Traits\{BelongsToTenant, Filterable};
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphMany};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Provider extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    protected $fillable = ['tenant_id', 'name', 'contact_person', 'email', 'phone', 'available_balance', 'is_active'];
    
    // Agregamos 'balances' para que el frontend lo reciba
    protected $appends = ['current_balance', 'balances'];

    protected $casts = ['available_balance' => 'float', 'is_active' => 'boolean'];

    public function internalTransactions(): MorphMany
    {
        return $this->morphMany(InternalTransaction::class, 'entity');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    /**
     * ✅ ESTA ES LA CLAVE: Agrupa los saldos por moneda
     */
    // En App\Models\Provider.php

    public function getBalancesAttribute()
    {
        return $this->ledgerEntries()
            ->where('type', 'payable')       // Solo lo que es deuda
            ->where('status', '!=', 'paid')  // Solo lo que no se ha pagado
            
            // ✅ EL FILTRO MÁGICO: 
            // Esto elimina todo lo que venga de Operaciones/Cambios automáticos.
            // Solo deja lo que se creó manualmente (donde no hay transaction_type).
            ->whereNull('transaction_type') 
            
            ->select('currency_id', \Illuminate\Support\Facades\DB::raw('SUM(amount - paid_amount) as total'))
            ->groupBy('currency_id')
            ->with('currency')
            ->get()
            ->map(function ($item) {
                return [
                    'currency_code' => $item->currency->code ?? '???',
                    'symbol'        => $item->currency->symbol ?? '$',
                    'amount'        => (float) $item->total
                ];
            });
    }

    // Mantenemos este por compatibilidad, pero ya no lo usaremos en la tabla principal
    public function getCurrentBalanceAttribute()
    {
        return $this->ledgerEntries()
            ->where('type', 'payable')
            ->where('status', '!=', 'paid')
            ->get()
            ->sum(fn($e) => $e->amount - $e->paid_amount);
    }

    public function scopeSearch(Builder $query, $term): Builder
    {
        $term = "%{$term}%";
        return $query->where('name', 'like', $term)->orWhere('contact_person', 'like', $term);
    }
}