<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('manage exchange rates');
    }

    public function rules(): array
    {
        $tenantId = Auth::user()->tenant_id;
        
        return [
            'from_currency_id' => ['required', 'exists:currencies,id'],
            'to_currency_id' => [
                'required', 
                'exists:currencies,id',
                // Asegura que no se intente establecer la tasa de la misma moneda (USD a USD)
                'different:from_currency_id'
            ],
            'rate' => ['required', 'numeric', 'min:0.000001'], // Seis decimales para la tasa
            'date' => ['required', 'date_format:Y-m-d'],
            
            // Regla de Unicidad (evita duplicar la tasa para la misma fecha y par de divisas en el mismo tenant)
            Rule::unique('exchange_rates')->where(function ($query) use ($tenantId) {
                return $query->where('tenant_id', $tenantId)
                             ->where('from_currency_id', $this->from_currency_id)
                             ->where('to_currency_id', $this->to_currency_id)
                             ->where('date', $this->date);
            }),
        ];
    }
}