<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\BelongsToTenant;

class Employee extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'identification_doc',
        'email',
        'phone',
        'position',
        'salary_amount',
        'currency_code',
        'payment_frequency',
        'payment_day_1',
        'payment_day_2',
        'is_active'
    ];

    // 1. IMPORTANTE: Esto le dice a Laravel que envíe el campo calculado a Vue
    protected $appends = ['pending_balance'];

    /**
     * Relación con el Libro Contable (Ledger).
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }

    /**
     * Saldo pendiente (Cambié el nombre de Salary a Balance para coincidir con Vue)
     * Laravel busca get[Nombre]Attribute -> pending_balance
     */
    public function getPendingBalanceAttribute()
    {
        // Si el modelo no se ha guardado aún, retornar 0
        if (!$this->exists) {
            return "0.00";
        }

        $balances = $this->ledgerEntries()
            ->where('type', 'payable') // Solo lo que la empresa debe pagar
            ->selectRaw('currency_code, SUM(original_amount - paid_amount) as balance')
            ->groupBy('currency_code')
            ->get();

        if ($balances->isEmpty()) {
            return "0.00"; // Retorna string para que la vista muestre "Al día"
        }

        // Formateamos para que se vea bonito: "200.00 USD"
        return $balances->filter(function($item) {
            return abs($item->balance) > 0.001; 
        })->map(function($item) {
            return number_format($item->balance, 2) . ' ' . $item->currency_code;
        })->implode(' | ');
    }
}