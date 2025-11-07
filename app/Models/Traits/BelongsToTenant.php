<?php
namespace App\Models\Traits;

use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    // Este método 'boot' se ejecuta automáticamente cuando se usa el Trait
    protected static function bootBelongsToTenant()
    {
        // 1. Aplica el Scope global para filtrar lecturas
        static::addGlobalScope(new TenantScope);

        // 2. Asigna automáticamente el tenant_id al crear
        static::creating(function ($model) {
            // Solo si el usuario está logueado y no es Superadmin
            if (Auth::check() && Auth::user()->tenant_id) {
                $model->tenant_id = Auth::user()->tenant_id;
            }
        });
    }
}