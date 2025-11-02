<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait HasTenant
{
    /**
     * Boot the trait and register the Global Scope
     */
    protected static function bootHasTenant()
    {
        // 1. Aplicar el Global Scope: 
        // Filtra todas las consultas si el usuario NO es un Super Admin.
        static::addGlobalScope('tenant_id', function (Builder $builder) {
            
            // Asumiendo que el usuario autenticado (via JWT) es accesible
            $user = Auth::user(); 

            // Solo aplicar el scope si el usuario está autenticado y NO es un Super Admin (is_admin = false)
            if ($user && !$user->is_admin) {
                $builder->where('tenant_id', $user->tenant_id);
            }
        });

        // 2. Establecer el tenant_id automáticamente al crear
        static::creating(function ($model) {
            $user = Auth::user();
            if ($user && !$user->is_admin && !$model->tenant_id) {
                $model->tenant_id = $user->tenant_id;
            }
        });
    }
}