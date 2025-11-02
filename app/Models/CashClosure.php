<?php

namespace App\Models;

use App\Models\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashClosure extends Model
{
    use HasFactory, HasTenant; // APLICAR EL TRAIT
    
    protected $fillable = ['tenant_id', 'cash_id', 'user_id', 'start_date', 'end_date', 'initial_balance', 'final_balance', 'difference'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function cash()
    {
        return $this->belongsTo(Cash::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
