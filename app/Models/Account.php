<?php

namespace App\Models;

use App\Models\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory, HasTenant; // APLICAR EL TRAIT
    
    protected $fillable = ['tenant_id', 'name', 'type', 'is_system', 'is_active'];
    
    // Relación N:1 con Tenant
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Relación 1:M con TransactionDetails (para ver qué transacciones la afectan)
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }
}
