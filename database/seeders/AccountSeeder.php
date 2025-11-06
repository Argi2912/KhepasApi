<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run()
    {
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();
        if (!$tenant) {
            $this->command->info('No se encontró el Tenant. Ejecute TenantSeeder primero.');
            return;
        }

        $accounts = [
            // Cuentas Maestras (is_system: true)
            ['name' => 'Cuentas por Pagar (Maestro)', 'type' => 'CXP', 'is_system' => true],
            ['name' => 'Cuentas por Cobrar (Maestro)', 'type' => 'CXC', 'is_system' => true],
            ['name' => 'Ingresos por Operaciones (Directo)', 'type' => 'INGRESS', 'is_system' => true],
            ['name' => 'Egresos por Operaciones (Directo)', 'type' => 'EGRESS', 'is_system' => true],
            ['name' => 'Cuenta Maestra de Efectivo y Bancos', 'type' => 'CASH', 'is_system' => true],
            ['name' => 'Ganancia por Tasa de Cambio', 'type' => 'INGRESS', 'is_system' => false],
            ['name' => 'Pérdida por Tasa de Cambio', 'type' => 'EGRESS', 'is_system' => false],

            // --- INICIO DE CORRECCIÓN (CUENTAS FALTANTES) ---
            
            // 1. Cuenta de Ingreso para Comisiones (de KHEPAS)
            // Nota: El controlador busca 'Ingresos por Comisiones (KHEPAS)'
            ['name' => 'Ingresos por Comisiones (KHEPAS)', 'type' => 'INGRESS', 'is_system' => false], 
            
            // 2. Cuentas de Costo para Comisiones (Pagadas a terceros)
            ['name' => 'Comisiones de Proveedores (Costo)', 'type' => 'EGRESS', 'is_system' => false],
            ['name' => 'Comisiones de Plataforma (Costo)', 'type' => 'EGRESS', 'is_system' => false],
            
            // --- FIN DE CORRECCIÓN ---
        ];

        foreach ($accounts as $accountData) {
            Account::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => $accountData['name'],
                ],
                [
                    'type' => $accountData['type'],
                    'is_system' => $accountData['is_system'],
                    'is_active' => true,
                ]
            );
        }
        
        $this->command->info('Cuentas contables maestras y de comisiones creadas/actualizadas.');
    }
}