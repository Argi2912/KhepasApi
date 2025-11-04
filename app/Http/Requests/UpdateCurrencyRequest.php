<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('manage exchange rates');
    }

    public function rules(): array
    {
        $currencyId = $this->route('currency')->id;

        return [
            'name' => 'nullable|string|max:50',
            'symbol' => [
                'nullable', 
                'string', 
                'max:10', 
                Rule::unique('currencies', 'symbol')->ignore($currencyId)
            ],
            'code' => [
                'nullable', 
                'string', 
                'max:5', 
                Rule::unique('currencies', 'code')->ignore($currencyId)
            ],
            'is_base' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }
}