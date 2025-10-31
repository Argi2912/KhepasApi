<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run()
    {
        // === Crear roles si no existen ===
        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $roleAnalyst = Role::firstOrCreate(['name' => 'analyst', 'guard_name' => 'api']);
        $roleUser = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'api']);

        // === Permisos de reportes ===
        $permissions = [
            'view client database',
            'view provider database',
            'view broker database',
            'view admin database',
            'view request history',
            'export databases',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // === Permisos generales (los que ya tenías) ===
        $generalPermissions = [
            'crear usuario', 'editar usuario', 'eliminar usuario', 'ver usuario',
            'crear caja', 'editar caja', 'eliminar caja', 'ver caja',
            'crear divisa', 'editar divisa', 'eliminar divisa', 'ver divisa',
            'crear tasa de cambio', 'editar tasa de cambio', 'eliminar tasa de cambio', 'ver tasa de cambio',
            'crear plataforma', 'editar plataforma', 'eliminar plataforma', 'ver plataforma',
            'crear solicitud', 'editar solicitud', 'eliminar solicitud', 'ver solicitud',
            'crear tipo de solicitud', 'editar tipo de solicitud', 'eliminar tipo de solicitud', 'ver tipo de solicitud',
            'crear transaccion', 'editar transaccion', 'eliminar transaccion', 'ver transaccion',
            'aprobar solicitud', 'rechazar solicitud',
        ];

        foreach ($generalPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // === Asignar permisos ===
        $roleAdmin->givePermissionTo(Permission::all());
        $roleAnalyst->givePermissionTo([
            'ver usuario', 'ver caja', 'ver divisa', 'ver tasa de cambio',
            'ver plataforma', 'crear solicitud', 'editar solicitud', 'ver solicitud',
            'crear transaccion', 'editar transaccion', 'ver transaccion',
        ]);
        $roleUser->givePermissionTo(['crear solicitud', 'ver solicitud']);
    }
}
