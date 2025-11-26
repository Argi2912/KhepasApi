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
use App\Models\Platform;
use App\Models\InternalTransaction;
use App\Models\CurrencyExchange;
use App\Models\LedgerEntry;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        // Ejecutamos todo en una transacción para velocidad y seguridad
        DB::transaction(function () {
            
            // =========================================================================
            // 1. TENANT Y CONFIGURACIÓN INICIAL
            // =========================================================================
            $tenant = Tenant::create(['name' => 'Estudio Kephas (Producción Demo)']);
            $tenantId = $tenant->id;

            // =========================================================================
            // 2. USUARIOS DEL SISTEMA (STAFF)
            // =========================================================================
            

                

            $analista = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Analista Kephas',
                'email' => 'analista@kephas.com',
                'password' => Hash::make('password'),
            ])->assignRole('analista');

            // Admin Principal
            $admin = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Admin Kephas',
                'email' => 'admin@kephas.com',
                'password' => Hash::make('password'),
            ]);
            $admin->assignRole('admin_tenant');

            // Cajeros (Operativos)
            $cajero1 = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Cajero Kephas',
                'email' => 'cajero@kephas.com',
                'password' => Hash::make('password'),
            ])->assignRole('cajero');

            $cajero2 = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Maria Caja',
                'email' => 'cajero.maria@khepas.com',
                'password' => Hash::make('password'),
            ])->assignRole('cajero');

            // Analista (Auditoría)
            $analista = User::create([
                'tenant_id' => $tenantId,
                'name' => 'Pedro Analista',
                'email' => 'analista.pedro@khepas.com',
                'password' => Hash::make('password'),
            ])->assignRole('analista');

            // =========================================================================
            // 3. CATALOGOS FINANCIEROS (Monedas, Plataformas, Brokers)
            // =========================================================================

            // Divisas
            $currencies = [
                ['code' => 'USD', 'name' => 'Dólar Americano'],
                ['code' => 'USDT', 'name' => 'Tether (USDT)'],
                ['code' => 'VES', 'name' => 'Bolívar Digital'],
                ['code' => 'EUR', 'name' => 'Euro'],
                ['code' => 'COP', 'name' => 'Peso Colombiano'],
            ];
            foreach ($currencies as $c) Currency::create(['tenant_id' => $tenantId] + $c);

            // Plataformas (Bancos / Pasarelas)
            $platforms = [
                ['name' => 'Zelle', 'email' => 'pagos@zelle.com'],
                ['name' => 'Binance Pay', 'email' => 'merchant@binance.com'],
                ['name' => 'Banesco Panamá', 'email' => null],
                ['name' => 'Banesco Venezuela', 'email' => null],
                ['name' => 'Efectivo Oficina', 'email' => null],
                ['name' => 'Mercantil', 'email' => null],
            ];
            $dbPlatforms = [];
            foreach ($platforms as $p) {
                $dbPlatforms[] = Platform::create(['tenant_id' => $tenantId] + $p);
            }

            // Brokers (Corredores Independientes)
            $brokersData = [
                ['name' => 'Inversiones Los Andes', 'email' => 'contacto@andes.com', 'rate' => 1.5],
                ['name' => 'CryptoFast Broker', 'email' => 'dealers@cryptofast.com', 'rate' => 0.5],
                ['name' => 'Consultora Financiera Global', 'email' => 'finanzas@global.com', 'rate' => 2.0],
            ];
            $dbBrokers = [];
            foreach ($brokersData as $b) {
                $dbBrokers[] = Broker::create([
                    'tenant_id' => $tenantId,
                    'name' => $b['name'],
                    'email' => $b['email'],
                    'default_commission_rate' => $b['rate']
                ]);
            }

            // =========================================================================
            // 4. CUENTAS BANCARIAS Y CAJA (Saldos Iniciales)
            // =========================================================================
            
            $accCajaFuerte = Account::create(['tenant_id' => $tenantId, 'name' => 'Caja Fuerte (USD)', 'currency_code' => 'USD', 'balance' => 15000.00]);
            $accZelleCorp  = Account::create(['tenant_id' => $tenantId, 'name' => 'Zelle Corporativo', 'currency_code' => 'USD', 'balance' => 5800.00]);
            $accBinance    = Account::create(['tenant_id' => $tenantId, 'name' => 'Binance Wallet Funding', 'currency_code' => 'USDT', 'balance' => 45000.00]);
            $accBanesco    = Account::create(['tenant_id' => $tenantId, 'name' => 'Banesco Nacional', 'currency_code' => 'VES', 'balance' => 250000.00]);
            $accEuros      = Account::create(['tenant_id' => $tenantId, 'name' => 'Caja Euros', 'currency_code' => 'EUR', 'balance' => 2000.00]);

            // =========================================================================
            // 5. CARTERA (Clientes y Proveedores)
            // =========================================================================
            
            // Creamos 15 clientes diversos
            $clients = Client::factory(15)->create(['tenant_id' => $tenantId]);
            
            // Proveedores de Liquidez
            $prov1 = Provider::factory()->create(['tenant_id' => $tenantId, 'name' => 'Liquidez Mayorista A']);
            $prov2 = Provider::factory()->create(['tenant_id' => $tenantId, 'name' => 'Cambios La Frontera']);

            // =========================================================================
            // 6. GENERACIÓN DE TRANSACCIONES (HISTÓRICO Y RECIENTE)
            // =========================================================================

            // A. MOVIMIENTOS INTERNOS (Gastos de Oficina, Ingresos Capital)
            // -------------------------------------------------------------
            // Ingreso Inicial
            InternalTransaction::create([
                'tenant_id' => $tenantId,
                'user_id' => $admin->id,
                'account_id' => $accCajaFuerte->id,
                'type' => 'income',
                'category' => 'Capital Inicial',
                'amount' => 10000,
                'description' => 'Aporte de socios',
                'transaction_date' => now()->subMonth(),
            ]);

            // Algunos gastos aleatorios en el último mes
            for ($i = 0; $i < 8; $i++) {
                InternalTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $cajero1->id,
                    'account_id' => $accBanesco->id,
                    'type' => 'expense',
                    'category' => $i % 2 == 0 ? 'Servicios' : 'Nómina',
                    'amount' => rand(500, 2000),
                    'description' => 'Pago operativo #' . $i,
                    'transaction_date' => now()->subDays(rand(1, 30)),
                ]);
            }

            // B. OPERACIONES DE CAMBIO (EXCHANGES & PURCHASES)
            // ------------------------------------------------
            // Generamos 20 transacciones simuladas
            
            foreach (range(1, 20) as $index) {
                // Alternar tipo de operación
                $type = $index % 3 == 0 ? 'purchase' : 'exchange'; // 1 de cada 3 es compra
                $status = $index > 15 ? 'pending' : 'completed'; // Las últimas 5 pendientes
                
                $client = $clients->random();
                $broker = rand(0, 1) ? $dbBrokers[array_rand($dbBrokers)] : null; // 50% chance de broker
                $adminUser = rand(0, 1) ? $cajero1 : $cajero2;
                
                // Configurar datos según tipo
                if ($type === 'exchange') {
                    // Intercambio: USDT -> VES
                    $from = $accBinance;
                    $to = $accBanesco;
                    $amountSent = rand(100, 1000);
                    $rate = 45.50 + (rand(0, 10) / 10); // Tasa variable
                    $amountReceived = $amountSent * $rate;
                    
                    // Comisiones
                    $commCharged = $amountSent * 0.02; // 2%
                    $commBroker = $broker ? $amountSent * 0.005 : 0; // 0.5% si hay broker
                    
                } else {
                    // Compra: Cliente da Zelle, recibe Efectivo USD
                    $from = $accCajaFuerte;
                    $to = $accZelleCorp;
                    $amountReceived = rand(500, 2000); // Entra Zelle
                    $rate = 1.03; // Costo por Zelle
                    $amountSent = $amountReceived / $rate; // Sale menos efectivo
                    
                    $commCharged = 20; // Fijo
                    $commBroker = 0;
                }

                $tx = CurrencyExchange::create([
                    'tenant_id' => $tenantId,
                    'number' => 'CE-' . str_pad($index, 5, '0', STR_PAD_LEFT),
                    'client_id' => $client->id,
                    'broker_id' => $broker?->id,
                    'provider_id' => null,
                    'admin_user_id' => $adminUser->id,
                    
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    
                    'amount_sent' => $amountSent,
                    'amount_received' => $amountReceived,
                    
                    // En purchase, exchange_rate suele ser la tasa base de compra
                    'exchange_rate' => $rate, 
                    
                    'commission_total_amount' => $commCharged,
                    'commission_provider_amount' => 0,
                    'commission_admin_amount' => 0,
                    
                    'status' => $status,
                    'created_at' => now()->subDays(rand(0, 45)), // Distribuidas en 45 días
                ]);

                // C. GENERAR DEUDAS / LEDGER (Solo para completadas y algunas pendientes)
                // ----------------------------------------------------------------------
                
                // 1. Comisión Ganada (Cuenta por Cobrar al Cliente - Simulamos que ya se cobró o se debe)
                if ($commCharged > 0) {
                    $ledgerStatus = $status === 'completed' ? 'paid' : 'pending';
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $tx->id,
                        'entity_type' => Client::class,
                        'entity_id' => $client->id,
                        'type' => 'receivable',
                        'status' => $ledgerStatus,
                        'amount' => $commCharged,
                        'description' => "Comisión Op #{$tx->number}",
                        'created_at' => $tx->created_at
                    ]);
                }

                // 2. Comisión Broker (Cuenta por Pagar - Simulamos deuda)
                if ($broker && $commBroker > 0) {
                    // Dejamos algunas pagadas y otras pendientes
                    $brokerLedgerStatus = rand(0, 1) ? 'paid' : 'pending';
                    
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'transaction_type' => CurrencyExchange::class,
                        'transaction_id' => $tx->id,
                        'entity_type' => Broker::class,
                        'entity_id' => $broker->id,
                        'type' => 'payable',
                        'status' => $brokerLedgerStatus,
                        'amount' => $commBroker,
                        'description' => "Comisión Broker {$broker->name} - Ref {$tx->number}",
                        'created_at' => $tx->created_at
                    ]);
                }
            }
        });
    }
}