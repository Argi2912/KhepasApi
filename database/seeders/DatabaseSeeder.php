<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // El orden es CRÍTICO para las llaves foráneas
        $this->call([
            TenantSeeder::class,
            CurrencySeeder::class,         // 1. Necesario para todo lo demás
            PermissionRoleSeeder::class, // 2. Necesario para UserSeeder
            AccountSeeder::class,        // 3. Necesario para CashSeeder
            CashSeeder::class,           // 4. Necesita Account y Currency
            UserSeeder::class,           // 5. Necesita Tenant y Roles
            ExchangeRateSeeder::class,   // 6. (NUEVO) Necesita Tenant y Currency
        ]);
    }
}