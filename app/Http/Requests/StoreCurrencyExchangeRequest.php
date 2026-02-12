<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurrencyExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autenticación ya la maneja Sanctum
    }

    public function rules(): array
    {
        // Reglas Base
        $rules = [
            'operation_type'          => ['required', Rule::in(['purchase', 'exchange'])],
            'client_id'               => 'required|exists:clients,id',
            'broker_id'               => 'nullable|exists:brokers,id',
            'provider_id'             => 'nullable|exists:providers,id',
            'admin_user_id'           => 'required|exists:users,id',
            
            // Cuentas
            'to_account_id'           => 'required|exists:accounts,id',
            'from_account_id'         => [
                'nullable', 
                Rule::requiredIf($this->input('capital_type') !== 'investor'), 
                'integer', 'exists:accounts,id'
            ],

            // Montos: Solo validamos el monto base y porcentajes. 
            // ❌ NO ACEPTAMOS 'commission_total_amount', etc.
            'amount_received'         => 'required|numeric|min:0.01',
            'amount_sent'             => 'required|numeric|min:0.01',

            // Porcentajes de Comisiones (Inputs permitidos)
            'commission_charged_pct'  => 'nullable|numeric|min:0|max:100',
            'commission_provider_pct' => 'nullable|numeric|min:0|max:100',
            'commission_broker_pct'   => 'nullable|numeric|min:0|max:100',
            
            // Logística
            'reference_id'            => 'nullable|string|max:255',
            'delivered'               => 'boolean',
            'paid'                    => 'boolean',
            'capital_type'            => 'required|in:own,investor',
            
            // Inversionista
            'investor_id'             => 'required_if:capital_type,investor|nullable|exists:investors,id',
            'investor_profit_pct'     => 'nullable|numeric|min:0',
        ];

        // Reglas Condicionales
        if ($this->input('operation_type') === 'exchange') {
            $rules['exchange_rate']        = 'required|numeric|min:0.00000001';
            $rules['platform_id']          = 'required|exists:platforms,id';
            $rules['commission_admin_pct'] = 'nullable|numeric|min:0|max:100';
        } else {
            // Compra de divisas
            $rules['buy_rate']      = 'required|numeric|min:0.00000001';
            $rules['received_rate'] = 'required|numeric|min:0.00000001';
            $rules['platform_id']   = 'nullable|exists:platforms,id';
        }

        return $rules;
    }
}