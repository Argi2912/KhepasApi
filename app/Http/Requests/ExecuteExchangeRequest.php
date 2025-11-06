<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Cash;
use App\Models\ExchangeRate;

class ExecuteExchangeRequest extends FormRequest
{
    // Propiedades privadas para almacenar los objetos cargados
    private $cashGiven;
    private $cashReceived;

    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('execute currency exchange');
    }

    public function rules(): array
    {
       return [
            'date' => 'required|date|before_or_equal:now',
            'cash_given_id' => 'required|exists:cashes,id',
            'cash_received_id' => 'required|exists:cashes,id|different:cash_given_id',
            'amount_given' => 'required|numeric|min:0.01',
            
            // IDs de los actores
            'customer_user_id' => 'required|exists:users,id', // Cliente es requerido
            'provider_user_id' => 'nullable|exists:users,id',
            'broker_user_id'   => 'required|exists:users,id', // Corredor (para tracking) es requerido

            // --- INICIO DE CORRECCIÓN DE NOMBRES ---
            // Los nombres deben coincidir con el Modal VUE
            'commission_provider_percentage' => 'required|numeric|min:0|max:100',
            'commission_company_percentage'  => 'required|numeric|min:0|max:100', // Era 'broker_percentage'
            'commission_platform_percentage' => 'required|numeric|min:0|max:100',
            // --- FIN DE CORRECCIÓN DE NOMBRES ---

            'description' => 'nullable|string|max:255',
        ];
    }
    
    /**
     * Valida y CALCULA toda la operación.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. Cargar Cajas (con sus relaciones)
            $this->cashGiven = Cash::with('account', 'currency')->find($this->cash_given_id);
            $this->cashReceived = Cash::with('account', 'currency')->find($this->cash_received_id);

            // 2. Validar Cajas (previniendo el error "account_id on null")
            if (!$this->cashGiven || !$this->cashGiven->account || !$this->cashGiven->currency) {
                return $validator->errors()->add('cash_given_id', 'La caja de origen no es válida o no tiene una cuenta/divisa asignada.');
            }
            if (!$this->cashReceived || !$this->cashReceived->account || !$this->cashReceived->currency) {
                return $validator->errors()->add('cash_received_id', 'La caja de destino no es válida o no tiene una cuenta/divisa asignada.');
            }
            if ($this->cashGiven->currency_id === $this->cashReceived->currency_id) {
                 return $validator->errors()->add('cash_received_id', 'Las cajas no pueden ser de la misma divisa.');
            }
            if ($this->cashGiven->balance < (float)$this->amount_given) {
                return $validator->errors()->add('amount_given', 'Fondos insuficientes en la caja de origen.');
            }

            // 3. Validar Tasa de Cambio Oficial (para el Asiento Contable)
            $rate = ExchangeRate::where('tenant_id', Auth::user()->tenant_id)
                                ->where('from_currency_id', $this->cashGiven->currency_id)
                                ->where('to_currency_id', $this->cashReceived->currency_id)
                                ->whereDate('date', '<=', $this->date) // La más reciente en o antes de la fecha
                                ->latest('date') 
                                ->first(); 
            
            if (!$rate) {
                return $validator->errors()->add('date', 'No existe una tasa de cambio oficial registrada para este par (o anterior a esta fecha).');
            }

            // --- 4. CÁLCULO DE COMISIONES Y TOTALES ---
            $amountGiven = (float)$this->amount_given;
            
            // Gastos (salen de la caja origen)
            $providerAmount = $amountGiven * ((float)$this->commission_provider_percentage / 100);
            $platformAmount = $amountGiven * ((float)$this->commission_platform_percentage / 100);
            $totalExpenseCommissions = $providerAmount + $platformAmount;

            // Ingreso (se aparta de la caja origen)
            $companyAmount = $amountGiven * ((float)$this->commission_company_percentage / 100);
            
            // Neto a Convertir
            $netAmountToConvert = $amountGiven - $totalExpenseCommissions - $companyAmount;
            
            if ($netAmountToConvert <= 0) {
                return $validator->errors()->add('amount_given', 'El monto a entregar es insuficiente para cubrir las comisiones.');
            }

            // Totales
            $amountReceived = $netAmountToConvert * (float)$rate->rate;
            $effectiveRate = ($amountGiven > 0) ? $amountReceived / $amountGiven : 0;


            // --- 5. Inyección de Datos ---
            // Inyectamos todos los valores calculados en el Request para que el Controlador solo los ejecute.
            $this->merge([
                'exchange_rate_object' => $rate,
                'cash_given_object'    => $this->cashGiven,
                'cash_received_object' => $this->cashReceived,
                
                'currency_given_id'    => $this->cashGiven->currency_id,
                'currency_received_id' => $this->cashReceived->currency_id,

                'provider_amount'      => $providerAmount,
                'platform_amount'      => $platformAmount,
                'company_amount'       => $companyAmount,
                'total_expense_commissions' => $totalExpenseCommissions,
                'net_amount_to_convert' => $netAmountToConvert,
                'amount_received'       => $amountReceived,
                'effective_rate'        => $effectiveRate,
            ]);
        });
    }
}