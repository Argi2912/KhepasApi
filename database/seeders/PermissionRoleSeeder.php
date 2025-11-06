<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar; // Importante

class PermissionRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // --- 1. Definición de Permisos (Nuevos y Reclasificados) ---
        $permissions = [
            // Módulo Home (Vista General)
            'view home dashboard',

            // Módulo de Solicitudes (CRUD de Solicitudes/Transacciones)
            'view requests', 'create request', 'edit request', 'delete request',

            // Permisos de Entidades (Bases de Datos)
            'view entity databases', // Acceso a la página 4
            'manage client entity',
            'manage provider entity',
            'manage broker entity',
            'manage administrator entity',

            // Permisos de Cuentas Contables y Lógica Transaccional (documento anterior)
            'register cxc', 'receive cxc payment', 'register cxp', 'pay cxp debt', 'register direct ingress', 'register direct egress',

            // Funcionalidad de Caja y Divisas
            'manage cashes', 'manage exchange rates', 'execute currency exchange', 'manage currencies',
            'start cash closure', 'end cash closure',

            // Módulo de Estadísticas
            'view statistics dashboard', // Acceso a la página 5
            
            // Permisos de Administración del Sistema
            'manage users', 'manage roles', 'manage system settings'
        ];
        
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        // --- 2. Definición de Roles (Guard 'api') ---

        // Rol 1: Super Admin (Acceso Total)
        $superAdminRole = Role::findOrCreate('Super Admin', 'api');
        $superAdminRole->givePermissionTo(Permission::all());

        // Rol 2: Administrador del Tenant (Acceso Total de Gestión)
        $adminRole = Role::findOrCreate('Tenant Admin', 'api');
        $adminRole->givePermissionTo([
            'view home dashboard',
            'view requests', 'create request', 'edit request', 'delete request',
            'view entity databases', 'manage client entity', 'manage provider entity', 'manage broker entity', 'manage administrator entity',
            'register cxc', 'receive cxc payment', 'register cxp', 'pay cxp debt', 'register direct ingress', 'register direct egress',
            'manage cashes', 'manage exchange rates', 'execute currency exchange',
            'start cash closure', 'end cash closure',
            'view statistics dashboard',
            'manage users', 'manage roles'
        ]);
        
        // Rol 3: Corredor (Broker)
        $brokerRole = Role::findOrCreate('Broker', 'api');
        $brokerRole->givePermissionTo([
            'view home dashboard',
            'view requests', 'create request', 
            'register cxc', // Podría registrar CXC por comisiones
            'execute currency exchange',
        ]);
        
        // Rol 4: Cliente (Acceso muy limitado)
        $clientRole = Role::findOrCreate('Client', 'api');
        $clientRole->givePermissionTo([
            'view home dashboard', // Solo sus datos personales
            'view requests', // Solo sus solicitudes
        ]);

        // Rol 5: Proveedor (Acceso muy limitado)
        $providerRole = Role::findOrCreate('Provider', 'api');
        $providerRole->givePermissionTo([
            'view home dashboard', // Solo cuentas por pagar a él
            'view requests', // Solo solicitudes de pago a él
        ]);
    }
}