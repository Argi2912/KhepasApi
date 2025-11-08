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
use Illuminate\Validation\ValidationException;

class TransactionService
{
    /**
     * Función de ayuda para generar números secuenciales.
     *
     * @param string $modelClass (Ej: CurrencyExchange::class)
     * @param string $prefix (Ej: 'CE-')
     * @return string (Ej: 'CE-00001')
     */
    private function generateSequentialNumber(string $modelClass, string $prefix): string
    {
        // 1. Obtener el último ID registrado para el tenant actual
        // (Asumimos que los modelos usan el Trait BelongsToTenant)
        // Usamos latest('id') para obtener el registro más reciente.
        $latest = $modelClass::latest('id')->first();
        
        $nextId = $latest ? $latest->id + 1 : 1;

        // 2. Formatear el número (Ej: 1 -> 00001)
        // Un padding de 5 dígitos nos da hasta 99999 transacciones.
        $sequential = str_pad($nextId, 5, '0', STR_PAD_LEFT);

        return $prefix . $sequential;
    }


    /**
     * Procesa una transacción de "Cambio de Divisas".
     */
    public function createCurrencyExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // 1. GENERAR EL NÚMERO SECUENCIAL
            $data['number'] = $this->generateSequentialNumber(CurrencyExchange::class, 'CE-');

            // 2. Cargar modelos
            $fromAccount = Account::findOrFail($data['from_account_id']);
            $toAccount = Account::findOrFail($data['to_account_id']);
            $broker = Broker::find($data['broker_id']); // Puede ser nulo
            $provider = Provider::find($data['provider_id']); // Puede ser nulo
            $adminUser = User::findOrFail($data['admin_user_id']);
            $amount = (float) $data['amount_received'];

            // 3. Calcular Comisiones (en valor monetario de la divisa de origen)
            $commChargedVal = $amount * ((float) $data['commission_charged_pct'] / 100);
            $commProviderVal = $amount * ((float) $data['commission_provider_pct'] / 100);
            $commAdminVal = $amount * ((float) $data['commission_admin_pct'] / 100);
            
            // 4. Calcular Tasa y Neto
            // Esta lógica asume que el frontend NO envía la tasa, sino que el backend la busca.
            // Si el frontend ya calculó, esta lógica debe cambiar.
            // Por ahora, seguimos la lógica de tus archivos originales donde la tasa no se envía.
            $exchangeRate = 1; // 🚨 ¡REEMPLAZAR ESTO CON LÓGICA DE TASA REAL!
                               // $rate = app(ExchangeRateService::class)->findRate($fromAccount->currency_code, $toAccount->currency_code);
                               // if(!$rate) throw new \Exception("No se encontró tasa de cambio.");
                               // $exchangeRate = $rate->rate;

            $totalCommission = $commChargedVal + $commProviderVal + $commAdminVal;
            
            // Asumimos que el cliente envía 100, las comisiones (1+1+1=3) se restan, y el neto (97) se convierte.
            // $netAmountToDeliver = ($amount - $totalCommission) * $exchangeRate; 
            
            // O, el cliente envía 100, las comisiones (1+1+1=3) se suman al débito, y los 100 se convierten.
            // Esta es la lógica del frontend:
            $netAmountToDeliver = $amount * $exchangeRate;
            $totalDebit = $amount + $commChargedVal + $commProviderVal + $commAdminVal;


            // 5. Validaciones
            if ($netAmountToDeliver <= 0) {
                throw ValidationException::withMessages(['amount_received' => 'El monto neto a entregar es cero o negativo.']);
            }
            
            // Validar saldo de la cuenta de origen
            if ($fromAccount->balance < $totalDebit) {
                throw ValidationException::withMessages(['from_account_id' => "Saldo insuficiente. Se requiere {$totalDebit} y la cuenta tiene {$fromAccount->balance}."]);
            }

            // 6. Crear la Transacción
            $transaction = CurrencyExchange::create($data);

            // 7. Actualizar Balances (Caja)
            // Debitar cuenta origen (Monto + Comisiones)
            $fromAccount->decrement('balance', $totalDebit);
            // Acreditar cuenta destino
            $toAccount->increment('balance', $netAmountToDeliver);

            // 8. Crear Asientos Contables (Payables/Receivables)
            // (La lógica de asientos contables va aquí...)

            return $transaction;
        });
    }

    /**
     * Procesa una transacción de "Compra de Dólares" (Ej: VES a USD).
     *
     * LÓGICA BASADA EN EL FRONTEND (Manual USD -> Auto VES):
     * 1. El frontend envía 'amount_received' como el MONTO BASE en VES (Ej: 4000 VES).
     * Este 'amount_received' fue calculado en el frontend: (100 USD * 40.0 Tasa Venta)
     * 2. El 'TransactionService' debe REPLICAR los cálculos del frontend para validar.
     */
    public function createDollarPurchase(array $data)
    {
        return DB::transaction(function () use ($data) {
            
            // 1. GENERAR EL NÚMERO SECUENCIAL
            $data['number'] = $this->generateSequentialNumber(DollarPurchase::class, 'DP-');

            // 2. Cargar Modelos
            $fromAccount = Account::findOrFail($data['from_account_id']); // Cuenta Origen (VES)
            $platformAccount = Account::findOrFail($data['platform_account_id']); // Cuenta Plataforma (Divisa)
            $broker = Broker::find($data['broker_id']);
            $provider = Provider::find($data['provider_id']);
            $adminUser = User::findOrFail($data['admin_user_id']);

            // 3. Validar Divisas de Cuentas
            if ($fromAccount->currency_code === $platformAccount->currency_code) {
                 throw ValidationException::withMessages(['from_account_id' => 'La cuenta de origen y la de plataforma no pueden tener la misma divisa.']);
            }
            if ($data['deliver_currency_code'] !== $platformAccount->currency_code) {
                 throw ValidationException::withMessages(['platform_account_id' => 'La divisa de la cuenta de plataforma no coincide con la divisa a entregar.']);
            }

            // 4. Definir variables base (enviadas desde el frontend)
            $vesReceived_Base = (float) $data['amount_received']; // Ej: 4000 VES (Calculado en frontend)
            $buyRate = (float) $data['buy_rate'];           // Ej: 39.0 (Costo)
            $sellRate = (float) $data['received_rate'];      // Ej: 40.0 (Venta)

            // 5. Calcular Monto en Divisa (USD)
            // Replicamos el cálculo del frontend para saber el monto en USD
            // (Este es el 'amount_to_deliver' del frontend)
            $usdDelivered_Client = $vesReceived_Base / $sellRate; // Ej: 4000 / 40.0 = 100 USD

            // 6. Calcular Comisiones (basadas en el monto en USD)
            $commCharged_USD = $usdDelivered_Client * ((float) $data['commission_charged_pct'] / 100);
            $commProvider_USD = $usdDelivered_Client * ((float) $data['commission_provider_pct'] / 100);

            // 7. Calcular Costos Totales (Replicando la lógica del frontend)
            
            // A. Costo Total en VES (Lo que paga el cliente)
            $commCharged_VES = $commCharged_USD * $sellRate; // Comisión Empresa convertida a VES
            $commProvider_VES = $commProvider_USD * $sellRate; // Comisión Proveedor convertida a VES
            $totalVesCost = $vesReceived_Base + $commCharged_VES + $commProvider_VES; // Ej: 4000 + (Coms en VES)

            // B. Costo Total en USD (Lo que sale de la plataforma)
            $totalUsdDebit_Platform = $usdDelivered_Client + $commProvider_USD;
            
            // 8. Validar Balances
            // A. ¿Tiene el cliente suficientes Bolívares?
            if ($fromAccount->balance < $totalVesCost) {
                throw ValidationException::withMessages(['from_account_id' => "Saldo insuficiente. Se requiere {$totalVesCost} {$fromAccount->currency_code} y la cuenta tiene {$fromAccount->balance}."]);
            }
            // B. ¿Tiene la plataforma suficientes Dólares?
            if ($platformAccount->balance < $totalUsdDebit_Platform) {
                 throw ValidationException::withMessages(['platform_account_id' => "Saldo de plataforma insuficiente. Se requiere {$totalUsdDebit_Platform} {$platformAccount->currency_code} y la cuenta tiene {$platformAccount->balance}."]);
            }

            // 9. Crear la Transacción
            // $data ya contiene 'from_account_id' y 'number'
            $purchase = DollarPurchase::create($data);
            
            // 10. Actualizar Balances (Caja)
            $fromAccount->decrement('balance', $totalVesCost);
            $platformAccount->decrement('balance', $totalUsdDebit_Platform);
            
            // 11. Crear Asientos Contables (Ledger)
            
            // A. Ganancia por Spread (Diferencial Cambiario)
            $grossProfitVes = $vesReceived_Base - ($usdDelivered_Client * $buyRate); // Ej: 4000 - (100 * 39.0) = 100 VES
            if ($grossProfitVes > 0) {
                $purchase->ledgerEntries()->create([
                    'tenant_id' => $purchase->tenant_id,
                    'description' => "Ganancia Spread (Sol. #{$purchase->number})",
                    'amount' => $grossProfitVes,
                    'type' => 'receivable', 'status' => 'paid',
                    'entity_id' => $broker ? $broker->id : null,
                    'entity_type' => $broker ? Broker::class : null,
                ]);
            }

            // B. Ganancia por Comisión de Empresa
            if ($commCharged_VES > 0) {
                 $purchase->ledgerEntries()->create([
                    'tenant_id' => $purchase->tenant_id,
                    'description' => "Comisión Empresa (Sol. #{$purchase->number})",
                    'amount' => $commCharged_VES,
                    'type' => 'receivable', 'status' => 'paid',
                    'entity_id' => $broker ? $broker->id : null,
                    'entity_type' => $broker ? Broker::class : null,
                ]);
            }

            // C. Comisión por Pagar al Proveedor
            if ($commProvider_USD > 0 && $provider) {
                $commProvider_Payable_VES = $commProvider_USD * $buyRate; // Costo de la comisión del proveedor
                $purchase->ledgerEntries()->create([
                    'tenant_id' => $purchase->tenant_id,
                    'description' => "Comisión Proveedor (Sol. #{$purchase->number})",
                    'amount' => $commProvider_Payable_VES,
                    'type' => 'payable', 'status' => 'pending',
                    'entity_id' => $provider->id,
                    'entity_type' => Provider::class,
                ]);
            }

            return $purchase->load('fromAccount', 'platformAccount', 'client');
        });
    }
}