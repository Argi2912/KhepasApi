<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant; // Si usas el trait de tenant

class Investor extends Model
{
    use HasFactory, BelongsToTenant; 
    // use BelongsToTenant; // Descomenta si usas tenancy

    protected $fillable = ['tenant_id', 'name', 'alias', 'email', 'phone', 'is_active'];

    // Relación: Un inversionista tiene muchas operaciones financiadas
    public function exchanges()
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    // Relación POLIMÓRFICA: Para ver el historial de deudas/pagos en el Ledger
    public function ledgerEntries()
    {
        return $this->morphMany(LedgerEntry::class, 'entity');
    }
}