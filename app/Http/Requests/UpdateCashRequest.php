<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('manage cashes');
    }

    public function rules(): array
    {
        // Obtiene el ID del tenant y el ID de la caja actual para ignorarla en la unicidad
        $tenantId = Auth::user()->tenant_id;
        $cashId = $this->route('cash')->id;
        
        return [
            'name' => [
                'nullable', 
                'string', 
                'max:100',
                // Asegura que el nombre de la caja sea único dentro del tenant, excluyendo la actual
                Rule::unique('cashes')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($cashId),
            ],
            'account_id' => [
                'nullable', 
                'exists:accounts,id',
                Rule::exists('accounts', 'id')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId)
                                 ->where('type', 'CASH');
                }),
            ],
        ];
    }
}