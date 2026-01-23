<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\BelongsToTenant;
use App\Models\InternalTransaction;

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

    protected $appends = ['available_balance', 'capital_historico'];

    public function exchanges()
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    // ✅ CORRECCIÓN CLAVE: Cambiado de hasMany a morphMany
    // Ahora busca transacciones donde (entity_type = Investor Y entity_id = ID_Inversionista)
    // en lugar de buscar por account_id (que ahora es nulo).
    public function transactions(): MorphMany
    {
        return $this->morphMany(InternalTransaction::class, 'entity');
    }

    // 1. CAPITAL BASE
    public function getCapitalHistoricoAttribute()
    {
        // Suma Historial (Ingresos)
        $historialSum = $this->transactions()
            ->where('type', 'income')
            ->sum('amount');

        // Suma Ledger (Deuda Capital)
        $ledgerSum = $this->ledgerEntries()
            ->where('type', 'payable')
            ->sum('original_amount');

        return max($historialSum, $ledgerSum);
    }

    // 2. SALDO DISPONIBLE
    public function getAvailableBalanceAttribute()
    {
        // PASO A: Obtener el Total Ingresado
        $totalIngresos = $this->capital_historico;

        // PASO B: Obtener Total Retirado (Egresos)
        // Gracias a la corrección en transactions(), esto ahora SÍ encontrará
        // los retiros aunque no tengan cuenta bancaria asociada.
        $totalRetiros = $this->transactions()
            ->where('type', 'expense')
            ->sum('amount');

        // PASO C: La Resta
        return $totalIngresos - $totalRetiros;
    }
}