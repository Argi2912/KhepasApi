<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Limpiar caché
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Lista Actualizada de Permisos
        $permissions = [
            'manage_tenants',
            'manage_users',
            'manage_clients',
            'manage_platforms', // <-- Gestión de plataformas
            
            // Nuevos Módulos Financieros
            'manage_transaction_requests',   // Solicitudes
            'manage_internal_transactions',  // Caja / Movimientos Internos
            'manage_exchanges',              // Cambios de Divisa (El motor principal)
            
            'view_dashboard',
            'view_statistics',
            'view_database_history',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        // 3. Asignar Permisos

        // Superadmin
        Role::firstOrCreate(['name' => 'superadmin'])
            ->syncPermissions(['manage_tenants']);

        // Admin Tenant (Dueño del negocio)
        Role::firstOrCreate(['name' => 'admin_tenant'])
            ->syncPermissions([
                'view_dashboard',
                'manage_users',
                'manage_clients',
                'manage_platforms',
                'manage_transaction_requests',
                'manage_internal_transactions',
                'manage_exchanges',
                'view_statistics',
                'view_database_history',
            ]);

        // Cajero (Operativo)
        Role::firstOrCreate(['name' => 'cajero'])
            ->syncPermissions([
                'view_dashboard', 
                'manage_transaction_requests', // Puede ver solicitudes
                'manage_exchanges',            // Puede ejecutar cambios
                'manage_internal_transactions' // Puede registrar gastos menores
            ]);

        // Analista (Solo lectura/Auditoría)
        Role::firstOrCreate(['name' => 'analista'])
            ->syncPermissions([
                'view_dashboard', 
                'view_statistics', 
                'view_database_history'
            ]);
            
        // Corredor (Limitado)
        Role::firstOrCreate(['name' => 'corredor'])
            ->syncPermissions(['view_dashboard']);
    }
}