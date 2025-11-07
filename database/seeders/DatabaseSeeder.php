<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // 1. Crear Roles y Permisos PRIMERO
            RolesAndPermissionsSeeder::class,
            
            // 2. Crear el Superadmin Global
            SuperAdminSeeder::class,
            
            // 3. Crear todos los datos de prueba del Tenant
            DemoTenantSeeder::class,
        ]);
    }
}