<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Cash;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CashSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();
        $accountType = Account::where('tenant_id', $tenant->id)
                                ->where('name', 'Cuenta Maestra de Efectivo y Bancos') 
                                ->where('type', 'CASH')
                                ->first();

        if (!$tenant || !$accountType) {
            $this->command->info('Tenant o Cuenta CASH no encontrada. Ejecute TenantSeeder y AccountSeeder primero.');
            return;
        }

        // Plataformas mencionadas en el Home Dashboard
        $cashes = [
            ['name' => 'Efectivo Local', 'account_id' => $accountType->id, 'tenant_id' => $tenant->id],
            ['name' => 'Plataforma Binance', 'account_id' => $accountType->id, 'tenant_id' => $tenant->id],
            ['name' => 'Cuenta Zelle', 'account_id' => $accountType->id, 'tenant_id' => $tenant->id],
            // Puedes añadir más cuentas de efectivo si son separadas
        ];

        foreach ($cashes as $cashData) {
            Cash::create($cashData);
        }
    }
}
