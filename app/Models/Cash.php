<?php

namespace App\Models;

use App\Models\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cash extends Model
{
    use HasFactory, HasTenant; // APLICAR EL TRAIT
    
    protected $fillable = ['tenant_id', 'account_id', 'name'];

    // Relación N:1 con Tenant
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Relación N:1 con Account (la cuenta contable a la que está vinculada esta caja)
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    
    // Relación 1:M con CashClosures
    public function closures()
    {
        return $this->hasMany(CashClosure::class);
    }
}
