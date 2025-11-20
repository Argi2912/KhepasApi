<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class, // 1. Roles
            SuperAdminSeeder::class,          // 2. Admin Global
            DemoTenantSeeder::class,          // 3. Tenant y Datos Operativos
        ]);
    }
}