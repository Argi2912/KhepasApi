<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    // La clave primaria es 'code' y no es autoincremental
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
    ];

    // Relaciones para asegurar que no se borren si están en uso
    public function accounts()
    {
        return $this->hasMany(Account::class, 'currency_code', 'code');
    }
    
    public function exchangeRatesFrom()
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency', 'code');
    }
    
    public function exchangeRatesTo()
    {
        return $this->hasMany(ExchangeRate::class, 'to_currency', 'code');
    }
}