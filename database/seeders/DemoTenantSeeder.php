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
use App\Models\Currency;
use App\Models\InternalTransaction; // 🚨 Nueva Modelo
use App\Models\TransactionRequest;  // 🚨 Nueva Modelo
use App\Models\CurrencyExchange;    // 🚨 Modelo Actualizado
use App\Services\TransactionService; 

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            
            // --- 1. Crear el Tenant ---
            $tenant = Tenant::create(['name' => 'Estudio Kephas (Demo)']);
            $tenantId = $tenant->id;

            // --- 2. Crear Usuarios ---
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
            ])->assignRole('cajero');

            $analista = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Analista Kephas',
                'email' => 'analista@kephas.com',
                'password' => Hash::make('password'),
            ])->assignRole('analista');
            
            $corredorUser = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Corredor Externo',
                'email' => 'corredor@kephas.com',
                'password' => Hash::make('password'),
            ])->assignRole('corredor');

            // --- 3. Crear Divisas ---
            $currencies = [
                ['code' => 'USD', 'name' => 'Dólar Americano'],
                ['code' => 'USDT', 'name' => 'Tether (Binance)'],
                ['code' => 'VES', 'name' => 'Bolívar Soberano'],
                ['code' => 'EUR', 'name' => 'Euro'],
            ];
            foreach ($currencies as $data) {
                Currency::create(['tenant_id' => $tenantId] + $data); 
            }

            // --- 4. Crear Broker ---
            $broker = Broker::create([
                'tenant_id' => $tenantId,
                'user_id' => $corredorUser->id,
                'default_commission_rate' => 1.5,
            ]);

            // --- 5. Crear Cuentas (Caja) ---
            $accountZelle = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Zelle (Corporativo)',
                'currency_code' => 'USD',
                'balance' => 15000,
            ]);
            
            $accountBinance = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Binance (USDT)',
                'currency_code' => 'USDT',
                'balance' => 30000,
            ]);
            
            $accountEfectivo = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Caja Fuerte (Oficina)',
                'currency_code' => 'USD',
                'balance' => 5000,
            ]);
            
            $accountVes = Account::create([
                'tenant_id' => $tenantId,
                'name' => 'Banesco (VES)',
                'currency_code' => 'VES',
                'balance' => 100000, // ~2000 USD aprox
            ]);

            // --- 6. Crear Clientes y Proveedores ---
            $client = Client::factory()->create(['tenant_id' => $tenantId, 'name' => 'Cliente Frecuente 1']);
            $provider = Provider::factory()->create(['tenant_id' => $tenantId, 'name' => 'Proveedor Liquidez A']);
            Client::factory(5)->create(['tenant_id' => $tenantId]);

            // ============================================================
            // 🚨 NUEVA LÓGICA DE TRANSACCIONES (SIMULACIÓN)
            // ============================================================

            // A. SIMULACIÓN: Transacción Interna (Ingreso de Capital)
            InternalTransaction::create([
                'tenant_id' => $tenantId,
                'user_id' => $admin->id,
                'account_id' => $accountEfectivo->id, // Entra a caja fuerte
                'type' => 'income',
                'category' => 'Aporte Capital',
                'amount' => 2000,
                'description' => 'Aporte extra de socio para liquidez',
                'transaction_date' => now()->subDays(2),
            ]);

            // B. SIMULACIÓN: Registro de Solicitud (Cliente quiere cambiar)
            TransactionRequest::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'type' => 'exchange',
                'source_origin' => 'Zelle Wells Fargo',
                'destination_target' => 'Efectivo Oficina',
                'amount' => 500,
                'currency_code' => 'USD',
                'status' => 'pending',
                'notes' => 'El cliente pasará en la tarde.',
            ]);

            // C. SIMULACIÓN: Cambio de Divisa (Ejecutado)
            // Caso: Cliente entrega 100 USDT y recibe VES.
            // Tasa Manual: 45.00 VES/USDT
            
            $amountReceivedFromClient = 100; // Entra a nuestra Binance
            $rate = 45.00;
            $amountSentToClient = 4500; // Sale de nuestro Banesco (100 * 45)
            
            // Comisiones (Calculadas manualmente para el ejemplo)
            // Digamos que cobramos 2 USDT de comisión total.
            // El cliente envía 102, nosotros cambiamos 100. O descontamos del envío.
            // Asumamos modelo: Cliente envía 100, cobramos 2%, cambiamos 98.
            // PERO, para simplificar este registro, usaremos montos directos.

            CurrencyExchange::create([
                'tenant_id' => $tenantId,
                'number' => 'CE-00001',
                
                // Actores
                'client_id' => $client->id,
                'admin_user_id' => $cajero->id,
                'broker_id' => $broker->id,
                'provider_id' => null, // Sin proveedor externo
                
                // Flujo de Dinero (Perspectiva de NUESTRAS cuentas)
                'to_account_id' => $accountBinance->id, // Entra dinero (USDT)
                'from_account_id' => $accountVes->id,   // Sale dinero (VES)
                
                // Valores Manuales
                'amount_received' => 100.00,  // Entraron 100 USDT
                'amount_sent' => 4500.00,     // Salieron 4500 VES (Neto entregado)
                'exchange_rate' => 45.00,     // Tasa del momento
                
                // Comisiones (Montos, no %)
                'commission_total_amount' => 2.00, // Ganamos 2 USDT en la operación (implícito o explícito)
                'commission_provider_amount' => 0,
                'commission_admin_amount' => 2.00,

                // Trazabilidad
                'trader_info' => 'JuanPerez - Binance P2P',
                'reference_id' => 'TX-BIN-99887766',
                'status' => 'completed'
            ]);

            // D. SIMULACIÓN: Compra de Divisa (Cliente paga VES, quiere USD Efectivo)
            // Tasa: 46.00 (Venta es más cara)
            
            CurrencyExchange::create([
                'tenant_id' => $tenantId,
                'number' => 'CE-00002',
                
                'client_id' => $client->id,
                'admin_user_id' => $cajero->id,
                'broker_id' => null,
                'provider_id' => null,
                
                // Flujo
                'to_account_id' => $accountVes->id,      // Entran VES
                'from_account_id' => $accountEfectivo->id, // Salen USD Efectivo
                
                // Valores
                'amount_received' => 9200.00, // Cliente paga 9200 VES
                'amount_sent' => 200.00,      // Cliente recibe 200 USD (9200 / 46)
                'exchange_rate' => 46.00,
                
                'commission_total_amount' => 5.00, // Comisión en divisa origen (VES) o destino?
                                                   // Usualmente se registra en la moneda base del reporte.
                                                   // Asumamos valor nominal referencial.
                'commission_provider_amount' => 0,
                'commission_admin_amount' => 5.00,

                'trader_info' => 'Caja Principal',
                'reference_id' => 'RECIBO-FISICO-001',
                'status' => 'completed'
            ]);

        });
    }
}