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
        // 1. Limpiar caché de permisos
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Definir Permisos del Sistema
        $permissions = [
            'manage_tenants',               // Superadmin
            
            // Administración del Tenant
            'view_dashboard',
            'view_statistics',
            'view_database_history',
            'manage_users',
            'manage_clients',               // Incluye Proveedores
            'manage_platforms',             // Bancos y Plataformas
            
            // --- Módulos Financieros ---
            'manage_transaction_requests',  // Ver solicitudes de clientes
            'manage_internal_transactions', // Caja Menor / Gastos / Ingresos
            'manage_exchanges',             // Operaciones de Cambio y Compra
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        // 3. Crear Roles y Asignar Permisos

        // A. SUPERADMIN (Dueño del SaaS)
        Role::firstOrCreate(['name' => 'superadmin'])
            ->syncPermissions(['manage_tenants']);

        // B. ADMIN TENANT (Dueño del Negocio)
        Role::firstOrCreate(['name' => 'admin_tenant'])
            ->syncPermissions([
                'view_dashboard',
                'view_statistics',
                'view_database_history',
                'manage_users',
                'manage_clients',
                'manage_platforms',
                'manage_transaction_requests',
                'manage_internal_transactions',
                'manage_exchanges',
            ]);

        // C. CAJERO (Operativo)
        Role::firstOrCreate(['name' => 'cajero'])
            ->syncPermissions([
                'view_dashboard', 
                'manage_transaction_requests', 
                'manage_exchanges',            
                'manage_internal_transactions',
                'manage_clients' // A menudo el cajero registra clientes
            ]);

        // D. ANALISTA (Auditoría)
        Role::firstOrCreate(['name' => 'analista'])
            ->syncPermissions([
                'view_dashboard', 
                'view_statistics', 
                'view_database_history'
            ]);
            
        // E. CORREDOR (Rol de usuario, si se requiere acceso al sistema)
        // Nota: Aunque los Brokers son entidades, podrían tener un usuario para ver sus comisiones.
        /* Role::firstOrCreate(['name' => 'corredor'])
            ->syncPermissions(['view_dashboard']); */
    }
}