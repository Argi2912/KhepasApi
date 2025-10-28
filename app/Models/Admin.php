<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    use HasFactory;

    /**
     * Define la tabla si es diferente de 'admins'
     * protected $table = 'nombre_de_tabla_admin'; 
     */

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'name',
    ];
}