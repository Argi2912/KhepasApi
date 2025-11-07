<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Provider;
use App\Models\Broker;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Currency; // 🚨 IMPORTAR EL MODELO DE DIVISAS
use App\Services\TransactionService; 

class DemoTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            
            // 1. Crear el Tenant
            $tenant = Tenant::create(['name' => 'Estudio Kephas (Demo)']);
            $tenantId = $tenant->id;

            // 2. Crear los Usuarios para este Tenant
            $admin = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Admin Kephas',
                'email' => 'admin@kephas.com',
                'password' => Hash::make('password'),
            ]);
            $admin->assignRole('admin_tenant');

            $cajero = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Cajero Kephas',
                'email' => 'cajero@kephas.com',
                'password' => Hash::make('password'),
            ]);
            $cajero->assignRole('cajero');

            $analista = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Analista Kephas',
                'email' => 'analista@kephas.com',
                'password' => Hash::make('password'),
            ]);
            $analista->assignRole('analista');
            
            $corredorUser = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Corredor Kephas',
                'email' => 'corredor@kephas.com',
                'password' => Hash::make('password'),
            ]);
            $corredorUser->assignRole('corredor');

            // 3. Crear Divisas (Currencies) 🚨 NUEVO
            $currencies = [
                ['code' => 'USD', 'name' => 'Dólar Americano'],
                ['code' => 'USDT', 'name' => 'Tether (Binance)'],
                ['code' => 'VES', 'name' => 'Bolívar Soberano'],
                ['code' => 'EUR', 'name' => 'Euro'],
                ['code' => 'COP', 'name' => 'Peso Colombiano'],
            ];

            foreach ($currencies as $data) {
                Currency::create(['tenant_id' => $tenantId] + $data); 
            }

            // 4. Crear el registro de Corredor (Broker)
            $broker = Broker::create([
                'tenant_id' => $tenantId,
                'user_id' => $corredorUser->id,
                'default_commission_rate' => 1.5,
            ]);

            // 5. Crear Cuentas (Caja) - USAR CÓDIGOS DE DIVISA
            $accountZelle = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Zelle (BofA)',
                'currency_code' => 'USD', // 🚨 Usando código
                'balance' => 10000,
            ]);
            
            $accountBinance = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Binance (USDT)',
                'currency_code' => 'USDT', // 🚨 Usando USDT
                'balance' => 25000,
            ]);
            
            $accountEfectivo = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Efectivo (Oficina)',
                'currency_code' => 'USD',
                'balance' => 5000,
            ]);
            
            $accountVes = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Mercantil (VES)',
                'currency_code' => 'VES',
                'balance' => 500000,
            ]);

            // 6. Crear "Cartera" (Clientes y Proveedores)
            $client = Client::factory()->create(['tenant_id' => $tenantId]);
            $provider = Provider::factory()->create(['tenant_id' => $tenantId]);
            
            Client::factory(10)->create(['tenant_id' => $tenantId]);
            Provider::factory(10)->create(['tenant_id' => $tenantId]);

            // 7. Crear Tasa de Cambio
            ExchangeRate::create([
                'tenant_id' => $tenantId,
                'from_currency' => 'USD',
                'to_currency' => 'VES',
                'rate' => 340.00,
            ]);
            
            // 8. Crear Transacciones (Usando el Servicio)
            $txService = app(TransactionService::class);

            // Simula una "Compra de Dólares" (Ahora Compra de Divisas)
            $txService->createDollarPurchase([
                'tenant_id' => $tenantId,
                'number' => 'DP-00001',
                'client_id' => $client->id,
                'broker_id' => $broker->id,
                'provider_id' => $provider->id,
                'admin_user_id' => $cajero->id, 
                'platform_account_id' => $accountBinance->id, 
                'amount_received' => 5000,   
                'deliver_currency_code' => 'USDT', // 🚨 CAMPO AGREGADO
                'buy_rate' => 335.0,          
                'received_rate' => 340.0,     
                'commission_charged_pct' => 0,
                'commission_provider_pct' => 1.0, 
            ]);
            
            // Simula un "Cambio de Divisas"
            $txService->createCurrencyExchange([
                'tenant_id' => $tenantId,
                'number' => 'CE-00001',
                'client_id' => $client->id,
                'broker_id' => $broker->id,
                'provider_id' => $provider->id,
                'admin_user_id' => $cajero->id,
                'from_account_id' => $accountZelle->id, 
                'to_account_id' => $accountVes->id, 
                'amount_received' => 1000, 
                'commission_charged_pct' => 5.0, 
                'commission_provider_pct' => 1.0, 
                'commission_admin_pct' => 1.0, 
            ]);
        });
    }
}