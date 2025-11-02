<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void`
     */
    public function run()
    {
       // Divisa base
        Currency::create([
            'name' => 'United States Dollar',
            'symbol' => '$',
            'code' => 'USD',
            'is_base' => true,
            'is_active' => true,
        ]);

        // Divisa secundaria de ejemplo
        Currency::create([
            'name' => 'Euro',
            'symbol' => '€',
            'code' => 'EUR',
            'is_base' => false,
            'is_active' => true,
        ]);
        
        // Puedes añadir más si es necesario (ej: Moneda local)
        Currency::create([
            'name' => 'Bolívar Soberano',
            'symbol' => 'Bs',
            'code' => 'BSs',
            'is_base' => false,
            'is_active' => true,
        ]);
    }
}
