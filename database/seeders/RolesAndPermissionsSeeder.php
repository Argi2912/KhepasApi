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

        // 2. Definir Permisos (Estos son los que el sistema verificará)
        $permissions = [
            'manage_tenants',               // Superadmin

            // --- Administración ---
            'view_dashboard',
            'view_statistics',
            'view_database_history',
            'manage_users',
            'manage_employees',             // (Faltaba este para RRHH/Admin)

            // --- Relaciones Comerciales ---
            'manage_clients',               // Clientes y Proveedores
            'manage_investors',             // (Faltaba este para Inversionistas)
            'manage_brokers',               // (Faltaba este para Corredores)

            // --- Configuración Financiera ---
            'manage_platforms',             // Bancos y Plataformas
            'manage_finance',               // (Faltaba este para Cuentas y Divisas)

            // --- Módulos Operativos y Reportes ---
            'view_reports',                 // (Faltaba este para el Analista)
            'manage_transaction_requests',
            'manage_internal_transactions', // Caja Menor / Gastos
            'manage_exchanges',             // Mesa de Cambio
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        // 3. Crear Roles y Asignar Permisos

        // A. SUPERADMIN (Dueño del SaaS)
        Role::firstOrCreate(['name' => 'superadmin'])
            ->syncPermissions(['manage_tenants']);

        // B. ADMIN TENANT (Dueño del Negocio) - VE TODO
        Role::firstOrCreate(['name' => 'admin_tenant'])
            ->syncPermissions([
                'view_dashboard',
                'view_statistics',
                'view_database_history',
                'manage_users',
                'manage_employees',         // ✅ Ahora ve Empleados
                'manage_clients',
                'manage_investors',         // ✅ Ahora ve Inversionistas
                'manage_brokers',           // ✅ Ahora ve Corredores
                'manage_platforms',
                'manage_finance',           // ✅ Ahora ve Config. Financiera
                'view_reports',             // ✅ Ahora ve Reportes
                'manage_transaction_requests',
                'manage_internal_transactions',
                'manage_exchanges',
            ]);

        // C. CAJERO (Operativo) - Solo Operaciones y Clientes
        Role::firstOrCreate(['name' => 'cajero'])
            ->syncPermissions([
                'view_dashboard',
                'manage_transaction_requests',
                'manage_exchanges',
                'manage_clients'
                // NOTA: No le damos 'manage_internal_transactions' (Caja fuerte)
                // ni 'manage_finance' (Configuración de bancos).
            ]);

        // D. ANALISTA (Auditoría) - Solo Reportes y Estadísticas
        Role::firstOrCreate(['name' => 'analista'])
            ->syncPermissions([
                'view_dashboard',
                'view_statistics',
                'view_database_history',
                'view_reports'              // ✅ Permiso clave para ver el módulo
            ]);
    }
}
