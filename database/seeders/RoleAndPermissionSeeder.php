<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roleadmin= Role::firstOrCreate(
            ['name' => 'admin']
        );

        $roleanalyst= Role::firstOrCreate(
            ['name' => 'analyst']
        );
        $roleuser= Role::firstOrCreate(
            ['name' => 'user']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear usuario']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar usuario']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar usuario']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver usuario']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear caja']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar caja']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar caja']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver caja']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear divisa']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar divisa']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar divisa']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver divisa']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear tasa de cambio']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar tasa de cambio']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar tasa de cambio']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver tasa de cambio']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear plataforma']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar plataforma']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar plataforma']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver plataforma']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear tipo de solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar tipo de solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar tipo de solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver tipo de solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'crear transaccion']
        );

        Permission::FirstOrCreate(
            ['name' => 'editar transaccion']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar transaccion']
        );

        Permission::FirstOrCreate(
            ['name' => 'ver transaccion']
        );

        Permission::FirstOrCreate(
            ['name' => 'aprobar solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'rechazar solicitud']
        );

        Permission::FirstOrCreate(
            ['name' => 'eliminar solicitud']
        );

        $roleadmin->givePermissionTo(Permission::all());
        $roleanalyst->givePermissionTo(['ver usuario','ver caja','ver divisa','ver tasa de cambio','ver plataforma','crear solicitud','editar solicitud','ver solicitud','crear transaccion','editar transaccion','ver transaccion']);
        $roleuser->givePermissionTo(['crear solicitud','ver solicitud']);

    }
}
