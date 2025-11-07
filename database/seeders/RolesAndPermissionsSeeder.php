<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Crear Permisos ---
        Permission::create(['name' => 'manage_tenants']); // Solo Superadmin

        Permission::create(['name' => 'view_dashboard']);
        Permission::create(['name' => 'manage_users']);         // CRUD de usuarios del tenant
        Permission::create(['name' => 'manage_clients']);         // CRUD de usuarios del tenant
        Permission::create(['name' => 'manage_requests']);      // Crear/Ver transacciones
        Permission::create(['name' => 'manage_rates']);        // Crear tasas de cambio
        Permission::create(['name' => 'view_statistics']);      // Ver reportes
        Permission::create(['name' => 'view_database_history']); // Ver logs y tablas de historial
        
        // --- Crear Roles y Asignar Permisos ---

        // 1. Superadmin (Global)
        Role::create(['name' => 'superadmin'])
            ->givePermissionTo('manage_tenants');

        // 2. Administrador (del Tenant)
        Role::create(['name' => 'admin_tenant'])
            ->givePermissionTo([
                'view_dashboard',
                'manage_users',
                'manage_clients',
                'manage_requests',
                'manage_rates',
                'view_statistics',
                'view_database_history',
            ]);

        // 3. Cajero (del Tenant)
        Role::create(['name' => 'cajero'])
            ->givePermissionTo([
                'view_dashboard',
                'manage_requests', // Su función principal
            ]);

        // 4. Analista (del Tenant)
        Role::create(['name' => 'analista'])
            ->givePermissionTo([
                'view_dashboard',
                'view_statistics', // Su función principal
                'view_database_history',
            ]);
            
        // 5. Corredor (del Tenant)
        Role::create(['name' => 'corredor'])
            ->givePermissionTo([
                'view_dashboard',
            ]);
    }
}