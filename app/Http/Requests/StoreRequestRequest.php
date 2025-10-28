<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0',
            'commission_charged' => 'required|numeric|min:0',
            'supplier_commission' => 'required|numeric|min:0',
            'admin_commission' => 'required|numeric|min:0',

            'source_currency_id' => 'required|integer|exists:currencies,id',
            'destination_currency_id' => 'required|integer|exists:currencies,id',
            
            'destination_amount' => 'nullable|numeric|min:0',
            'applied_exchange_rate' => 'nullable|numeric|min:0',

            'request_type_id' => 'required|integer|exists:request_types,id',
            
            'client_id' => 'required|integer|exists:users,id',
            'broker_id' => 'required|integer|exists:users,id',
            'supplier_id' => 'required|integer|exists:users,id',
            'admin_id' => 'required|integer|exists:users,id',
            
            'source_platform_id' => 'required|integer|exists:platforms,id',
            'destination_platform_id' => 'required|integer|exists:platforms,id',
        ];
    }
}
