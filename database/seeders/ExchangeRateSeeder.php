<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\Currency;
use App\Models\ExchangeRate;

class ExchangeRateSeeder extends Seeder
{
    public function run()
    {
        
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();
        if (!$tenant) {
             $this->command->info('Tenant no encontrado, saltando ExchangeRateSeeder.');
             return;
        }
        
        try {
            $usd_id = Currency::where('code', 'USD')->firstOrFail()->id;
            $ves_id = Currency::where('code', 'VES')->firstOrFail()->id;

            // Tasa de ayer
            ExchangeRate::create([
                'tenant_id' => $tenant->id,
                'from_currency_id' => $usd_id,
                'to_currency_id' => $ves_id,
                'rate' => 40.85,
                'date' => now()->subDay()->toDateString(),
            ]);

            // Tasa de hoy
            ExchangeRate::create([
                'tenant_id' => $tenant->id,
                'from_currency_id' => $usd_id,
                'to_currency_id' => $ves_id,
                'rate' => 41.05,
                'date' => now()->toDateString(),
            ]);

        } catch (\Exception $e) {
            $this->command->error('Error al crear Tasas de Cambio: ' . $e->getMessage());
            $this->command->info('Asegúrese de ejecutar CurrencySeeder primero.');
        }
    }
}