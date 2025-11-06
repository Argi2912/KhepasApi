<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Cash;
use App\Models\Currency;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CashSeeder extends Seeder
{
    public function run()
    {
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();

        $accountType = Account::where('tenant_id', $tenant->id)
                                ->where('name', 'Cuenta Maestra de Efectivo y Bancos') 
                                ->where('type', 'CASH')
                                ->first();

        // --- INICIO DE LA CORRECCIÓN ---
        // Buscar las divisas por los códigos correctos (VES en lugar de BSs)
        $usd = Currency::where('code', 'USD')->first();
        $eur = Currency::where('code', 'EUR')->first();
        $ves = Currency::where('code', 'VES')->first(); // Buscando VES
        // --- FIN DE LA CORRECCIÓN ---

        // 4. Validar que todo exista
        if (!$tenant || !$accountType || !$usd || !$eur || !$ves) {
            $this->command->error('Error Fatal en CashSeeder: Faltan dependencias.');
            if (!$tenant) $this->command->info('-> Tenant "Demo Exchange Services Inc." no encontrado.');
            if (!$accountType) $this->command->info('-> Cuenta "Cuenta Maestra de Efectivo y Bancos" no encontrada.');
            if (!$usd) $this->command->info('-> Divisa con código "USD" no encontrada.');
            if (!$eur) $this->command->info('-> Divisa con código "EUR" no encontrada.');
            if (!$ves) $this->command->info('-> Divisa con código "VES" no encontrada.'); // Validando VES
            $this->command->info('Asegúrese de ejecutar TenantSeeder, AccountSeeder y CurrencySeeder primero.');
            return;
        }

        // 5. Definir las Cajas CON currency_id
        $cashes = [
            [
                'tenant_id' => $tenant->id,
                'account_id' => $accountType->id,
                'currency_id' => $usd->id, 
                'name' => 'Binance (USDT)', 
                'balance' => 50000.00
            ],
            [
                'tenant_id' => $tenant->id,
                'account_id' => $accountType->id,
                'currency_id' => $usd->id, 
                'name' => 'Zelle (USD)', 
                'balance' => 25000.00
            ],
            [
                'tenant_id' => $tenant->id,
                'account_id' => $accountType->id,
                'currency_id' => $ves->id, // Asignar Bolívares (VES)
                'name' => 'Efectivo Oficina (VES)', 
                'balance' => 1500000.00
            ],
            [
                'tenant_id' => $tenant->id,
                'account_id' => $accountType->id,
                'currency_id' => $eur->id, 
                'name' => 'Reserva (EUR)', 
                'balance' => 10000.00
            ],
        ];

        foreach ($cashes as $cashData) {
            Cash::updateOrCreate(
                [
                    'tenant_id' => $cashData['tenant_id'],
                    'name' => $cashData['name'],
                ],
                $cashData
            );
        }
        
        $this->command->info('Cajas (Cashes) creadas y asignadas a divisas exitosamente.');
    }
}