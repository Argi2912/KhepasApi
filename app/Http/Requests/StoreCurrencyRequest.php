<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Asumo el permiso 'manage currencies'
        return Auth::check() && Auth::user()->can('manage exchange rates');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            // El 'code' y 'symbol' deben ser únicos
            'symbol' => [
                'required', 
                'string', 
                'max:10', 
                Rule::unique('currencies', 'symbol')
            ],
            'code' => [
                'required', 
                'string', 
                'max:5', 
                Rule::unique('currencies', 'code')
            ],
            'is_base' => 'required|boolean',
            'is_active' => 'required|boolean',
        ];
    }
}