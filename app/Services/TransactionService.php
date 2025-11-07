<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Broker;
use App\Models\CurrencyExchange;
use App\Models\DollarPurchase;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TransactionService
{
    /**
     * Procesa una transacción de "Cambio de Divisas".
     *
     * Lógica de Comisiones (Asumida):
     * - amount_received: Total que entrega el cliente.
     * - Las 3 comisiones (% cobrada, % proveedor, % admin) se calculan sobre el 'amount_received'.
     * - El total de comisiones se resta del 'amount_received' para obtener el neto a entregar.
     */
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. Cargar modelos
            $fromAccount = Account::findOrFail($data['from_account_id']);
            $toAccount = Account::findOrFail($data['to_account_id']);
            $broker = Broker::findOrFail($data['broker_id']);
            $provider = Provider::findOrFail($data['provider_id']);
            $adminUser = User::findOrFail($data['admin_user_id']); // El admin que registra
            $amount = (float) $data['amount_received'];

            // 2. Calcular Comisiones (en valor monetario)
            $commBrokerVal = $amount * ((float) $data['commission_charged_pct'] / 100);
            $commProviderVal = $amount * ((float) $data['commission_provider_pct'] / 100);
            $commAdminVal = $amount * ((float) $data['commission_admin_pct'] / 100);

            $totalCommission = $commBrokerVal + $commProviderVal + $commAdminVal;
            $netToDeliver = $amount - $totalCommission;

            // 3. Actualizar Balances (Caja)
            $fromAccount->decrement('balance', $amount);
            $toAccount->increment('balance', $netToDeliver);

            // 4. Crear el registro de la transacción
            $exchange = CurrencyExchange::create($data);

            // 5. Crear Asientos Contables (Por Pagar)
            // Asiento por pagar al Corredor (Broker)
            $exchange->ledgerEntries()->create([
                'tenant_id' => $exchange->tenant_id,
                'description' => "Comisión Corredor (Sol. #{$exchange->number})",
                'amount' => $commBrokerVal,
                'type' => 'payable',
                'status' => 'pending',
                'entity_id' => $broker->id,
                'entity_type' => Broker::class,
            ]);

            // Asiento por pagar al Proveedor
            $exchange->ledgerEntries()->create([
                'tenant_id' => $exchange->tenant_id,
                'description' => "Comisión Proveedor (Sol. #{$exchange->number})",
                'amount' => $commProviderVal,
                'type' => 'payable',
                'status' => 'pending',
                'entity_id' => $provider->id,
                'entity_type' => Provider::class,
            ]);
            
            // Asiento de ganancia (Admin) - lo marcamos como 'receivable' (por cobrar a la operación)
            // y luego lo marcamos como 'paid' (cobrado).
            $exchange->ledgerEntries()->create([
                'tenant_id' => $exchange->tenant_id,
                'description' => "Ganancia Admin (Sol. #{$exchange->number})",
                'amount' => $commAdminVal,
                'type' => 'receivable', // Es la ganancia
                'status' => 'paid',     // Se cobra de la misma operación
                'entity_id' => $adminUser->id,
                'entity_type' => User::class,
            ]);


            return $exchange;
        });
    }

    /**
     * Procesa una transacción de "Compra de Dólares".
     *
     * Lógica de Comisiones (Asumida):
     * - Se asume una lógica similar a la anterior para simplificar.
     * - La ganancia real (por spread de tasas) es más compleja y se calculará en reportes.
     * - Aquí solo registraremos las comisiones explícitas.
     */
    public function createDollarPurchase(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. Cargar modelos
            $platformAccount = Account::findOrFail($data['platform_account_id']);
            $provider = Provider::findOrFail($data['provider_id']);
            $broker = Broker::findOrFail($data['broker_id']);
            
            // 2. Crear la transacción
            $purchase = DollarPurchase::create($data);
            
            $amount = (float) $data['amount_received']; // Asumimos 2500 VES

            // 3. Calcular Ganancia Bruta (Spread) en VES
            // Cliente paga 240. Casa compra a 230.
            // USD Entregados al cliente = 2500 / 240 = 10.416 USD
            // Costo de esos USD = 10.416 * 230 = 2400 VES
            // Ganancia Bruta (Spread) = 2500 - 2400 = 100 VES
            $usdDelivered = $amount / (float) $data['received_rate'];
            $costOfUsdInVes = $usdDelivered * (float) $data['buy_rate'];
            $grossProfitVes = $amount - $costOfUsdInVes;

            // 4. Calcular Comisión Proveedor (sobre el monto en VES)
            $commProviderVal = $amount * ((float) $data['commission_provider_pct'] / 100);

            // 5. Actualizar Balances (Caja)
            // Resta los USD entregados al cliente de la plataforma
            $platformAccount->decrement('balance', $usdDelivered);
            // (Faltaría sumar los VES a una cuenta de VES, pero seguimos el modelo)

            // 6. Crear Asientos Contables
            // Asiento por pagar al Proveedor
            $purchase->ledgerEntries()->create([
                'tenant_id' => $purchase->tenant_id,
                'description' => "Comisión Proveedor (Sol. #{$purchase->number})",
                'amount' => $commProviderVal, // Esto está en VES
                'type' => 'payable',
                'status' => 'pending',
                'entity_id' => $provider->id,
                'entity_type' => Provider::class,
            ]);

            // Asiento de Ganancia Bruta (Spread)
            $purchase->ledgerEntries()->create([
                'tenant_id' => $purchase->tenant_id,
                'description' => "Ganancia Spread (Sol. #{$purchase->number})",
                'amount' => $grossProfitVes, // Esto está en VES
                'type' => 'receivable',
                'status' => 'paid', // Se cobra de la misma operación
                'entity_id' => $broker->id, // Lo asociamos al broker que hizo la op.
                'entity_type' => Broker::class,
            ]);
            
            return $purchase;
        });
    }
}