<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequestRequest extends FormRequest
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
        $statusEnum = ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'];

        return [
            'amount' => 'sometimes|numeric|min:0',
            'commission_charged' => 'sometimes|numeric|min:0',
            'supplier_commission' => 'sometimes|numeric|min:0',
            'admin_commission' => 'sometimes|numeric|min:0',
            
            'source_currency_id' => 'sometimes|integer|exists:currencies,id',
            'destination_currency_id' => 'sometimes|integer|exists:currencies,id',
            
            'destination_amount' => 'nullable|numeric|min:0',
            'applied_exchange_rate' => 'nullable|numeric|min:0',

            'request_type_id' => 'sometimes|integer|exists:request_types,id',
            
            // Al actualizar, SÍ permitimos cambiar el estado.
            'status' => ['sometimes', Rule::in($statusEnum)],
            
            // Regla de negocio: si se rechaza, debe haber una razón.
            'rejection_reason' => 'nullable|string|max:1000|required_if:status,rejected',

            'client_id' => 'sometimes|integer|exists:users,id',
            'broker_id' => 'sometimes|integer|exists:users,id',
            'supplier_id' => 'sometimes|integer|exists:users,id',
            'admin_id' => 'sometimes|integer|exists:users,id',
            
            'source_platform_id' => 'sometimes|integer|exists:platforms,id',
            'destination_platform_id' => 'sometimes|integer|exists:platforms,id',
        ];
    }
}
