<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active'];

    // Relación 1:M con Usuarios
    public function users()
    {
        return $this->hasMany(User::class);
    }
    
    // Relación 1:M con Cuentas (Accounts)
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
    
    // Añade el resto de relaciones hasMany (Transactions, Cashes, ExchangeRates, etc.)
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
