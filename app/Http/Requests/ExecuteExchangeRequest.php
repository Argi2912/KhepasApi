<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\ExchangeRate;
use App\Models\Currency;

class ExecuteExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo usuarios con permiso para 'execute currency exchange' pueden realizar esta operación
        return Auth::check() && Auth::user()->can('execute currency exchange');
    }

    public function rules(): array
    {
        return [
            // Cajas y Monedas
            'cash_given_id' => ['required', 'exists:cashes,id'],
            'cash_received_id' => ['required', 'exists:cashes,id', 'different:cash_given_id'],
            'currency_given_id' => ['required', 'exists:currencies,id'],
            'currency_received_id' => ['required', 'exists:currencies,id', 'different:currency_given_id'],
            
            // Montos y Tasa
            'amount_given' => ['required', 'numeric', 'min:0.01'],
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
    
    /**
     * Lógica de validación adicional (Chequear tasa de cambio)
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            if ($this->fails()) {
                return;
            }

            $tenantId = Auth::user()->tenant_id;
            
            // 1. Verificar si existe la Tasa de Cambio para hoy
            $rate = ExchangeRate::where('tenant_id', $tenantId)
                                ->where('from_currency_id', $this->currency_given_id)
                                ->where('to_currency_id', $this->currency_received_id)
                                ->whereDate('date', now()->toDateString())
                                ->first();
            
            if (!$rate) {
                // Si la tasa directa no existe, buscar la inversa o requerir la cotización
                $validator->errors()->add('exchange_rate', 'No existe una tasa de cambio oficial registrada para este par de divisas y fecha.');
            } else {
                // Almacenar la tasa y la cuenta para usar en el controlador
                $this->merge(['exchange_rate_object' => $rate]);
            }

            // 2. Verificar que el balance de la caja dada es suficiente
            // NOTA: Esto requiere calcular el saldo de la caja en tiempo real, 
            // que se deja como mejora, pero es una validación crítica.
        });
    }
}