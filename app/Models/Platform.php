<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToTenant; // 1. Importar
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Platform extends Model
{
   use HasFactory, BelongsToTenant; // 2. Añadir trait
    
    // 3. Definir campos
    protected $fillable = ['name', 'email', 'phone', 'tenant_id'];
}
