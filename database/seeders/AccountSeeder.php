<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Obtener el Tenant de prueba
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();

        if (!$tenant) {
            $this->command->info('No se encontró el Tenant. Ejecute TenantSeeder primero.');
            return;
        }

        // 2. Definición de Cuentas Maestras
        $accounts = [
            // Cuentas Contables Maestras (is_system: true)
            ['name' => 'Cuentas por Pagar (Maestro)', 'type' => 'CXP', 'is_system' => true],
            ['name' => 'Cuentas por Cobrar (Maestro)', 'type' => 'CXC', 'is_system' => true],
            ['name' => 'Ingresos por Operaciones (Directo)', 'type' => 'INGRESS', 'is_system' => true],
            ['name' => 'Egresos por Operaciones (Directo)', 'type' => 'EGRESS', 'is_system' => true],
            
            // Cuenta de Control de Efectivo/Caja
            ['name' => 'Cuenta Maestra de Efectivo y Bancos', 'type' => 'CASH', 'is_system' => true],

            // Cuentas Específicas Requeridas por la Lógica (is_system: false)

            // REQUERIDA PARA STATSCONTROLLER: Ingresos por Comisiones
            ['name' => 'Ingresos por Comisiones', 'type' => 'INGRESS', 'is_system' => false], 
            
            // Cuentas de Ganancia/Pérdida por Intercambio
            ['name' => 'Ganancia por Tasa de Cambio', 'type' => 'INGRESS', 'is_system' => false],
            ['name' => 'Pérdida por Tasa de Cambio', 'type' => 'EGRESS', 'is_system' => false],
        ];

        // 3. Creación o Actualización de Cuentas
        foreach ($accounts as $accountData) {
            $accountData['tenant_id'] = $tenant->id;
            Account::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $accountData['name']], 
                $accountData
            );
        }
    }
}
