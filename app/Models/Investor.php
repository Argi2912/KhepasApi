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

    // Permitimos flexibilidad para encontrar datos viejos
    public function transactions()
    {
        return $this->hasMany(InternalTransaction::class, 'account_id');
    }

    // 1. CAPITAL BASE (Tus $2,000)
    // Busca el monto mayor entre el Historial y el Ledger
    public function getCapitalHistoricoAttribute()
    {
        // Suma Historial (si existe)
        $historialSum = $this->transactions()
            ->where('type', 'income')
            ->where(function($q) {
                $q->where('source_type', 'investor')->orWhere('source_type', 'account');
            })
            ->sum('amount');

        // Suma Ledger (Cuentas por Pagar)
        $ledgerSum = $this->ledgerEntries()
            ->where('type', 'payable')
            ->sum('original_amount');

        // Retorna el mayor (En tu caso, los $2,000 del Ledger)
        return max($historialSum, $ledgerSum);
    }

    // 2. SALDO DISPONIBLE (Lo que te queda)
    // FÓRMULA: (Capital Base) - (Total Retiros)
    public function getAvailableBalanceAttribute()
    {
        // PASO A: Obtener el Total Ingresado (Reutilizamos la lógica de arriba)
        $totalIngresos = $this->capital_historico; // Esto vale 2,000

        // PASO B: Obtener Total Retirado (Egresos)
        // Sumamos todas las salidas de dinero (Transferencias a tus cuentas)
        $totalRetiros = $this->transactions()
            ->where('type', 'expense')
            ->where(function($q) {
                $q->where('source_type', 'investor')->orWhere('source_type', 'account');
            })
            ->sum('amount'); // Esto vale 1,000

        // PASO C: La Resta
        // 2,000 - 1,000 = 1,000
        return $totalIngresos - $totalRetiros;
    }
}