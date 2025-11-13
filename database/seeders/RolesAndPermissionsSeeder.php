<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar; // 🚨 1. IMPORTAR

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 2. Limpiar caché de permisos
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Lista de Permisos ---
        $permissions = [
            'manage_tenants',
            'manage_platforms', // <-- El permiso que necesitas
            'view_dashboard',
            'manage_users',
            'manage_clients',
            'manage_requests',
            'manage_rates',
            'view_statistics',
            'view_database_history',
        ];

        // 3. Crear todos los permisos (Modo Seguro)
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        // --- Asignar Permisos a Roles (Modo Seguro) ---

        // 1. Superadmin
        Role::firstOrCreate(['name' => 'superadmin'])
            ->syncPermissions(['manage_tenants']); // syncPermissions es más limpio

        // 2. Administrador (del Tenant)
        Role::firstOrCreate(['name' => 'admin_tenant'])
            ->syncPermissions([ // 🚨 syncPermissions asegura que tenga esta lista
                'view_dashboard',
                'manage_users',
                'manage_clients',
                'manage_requests',
                'manage_rates',
                'view_statistics',
                'view_database_history',
                'manage_platforms', // <-- Asignando el permiso
            ]);

        // 3. Cajero
        Role::firstOrCreate(['name' => 'cajero'])
            ->syncPermissions(['view_dashboard', 'manage_requests']);

        // 4. Analista
        Role::firstOrCreate(['name' => 'analista'])
            ->syncPermissions(['view_dashboard', 'view_statistics', 'view_database_history']);
            
        // 5. Corredor
        Role::firstOrCreate(['name' => 'corredor'])
            ->syncPermissions(['view_dashboard']);
    }
}