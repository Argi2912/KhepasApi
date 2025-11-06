<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run()
    {

       // Divisa base
        Currency::create([
            'name' => 'Dólar Estadounidense',
            'symbol' => '$',
            'code' => 'USD',
            'is_base' => true,
            'is_active' => true,
        ]);

        // Divisa secundaria (Local)
        Currency::create([
            'name' => 'Bolívar Digital',
            'symbol' => 'Bs.',
            'code' => 'VES',
            'is_base' => false,
            'is_active' => true,
        ]);
        
        // Divisa terciaria (Opcional)
        Currency::create([
            'name' => 'Euro',
            'symbol' => '€',
            'code' => 'EUR',
            'is_base' => false,
            'is_active' => true,
        ]);
    }
}