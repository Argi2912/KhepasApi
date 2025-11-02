<?php

namespace App\Models;

use App\Models\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasTenant; // APLICAR EL TRAIT
    
    protected $fillable = ['tenant_id', 'user_id', 'date', 'description', 'reference_code', 'status'];

    // Relación N:1 con Tenant
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Relación N:1 con User (usuario que realizó la transacción)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relación 1:M con Transaction Details
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }
    
    // Relación 1:M para seguir transacciones
    public function relatedAccounts()
    {
        return $this->hasMany(RelatedAccount::class, 'main_transaction_id');
    }
}
