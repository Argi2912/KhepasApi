<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Solo aplica si el usuario está logueado Y tiene un tenant_id (no es Superadmin)
        if (Auth::check() && Auth::user()->tenant_id) {
            // Asume que la tabla tiene 'tenant_id'. 
            // Usamos getTable() para evitar ambigüedad en Joins.
            $builder->where($model->getTable() . '.tenant_id', Auth::user()->tenant_id);
        }
    }
}
