<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

// 💡 Importa las clases de Spatie

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            TenantSeeder::class,
            CurrencySeeder::class,
            PermissionRoleSeeder::class,
            AccountSeeder::class, // Necesita Tenant
            CashSeeder::class,    // Necesita Tenant y Account
            UserSeeder::class,    // Necesita Tenant y Roles
        ]);
    }
}
