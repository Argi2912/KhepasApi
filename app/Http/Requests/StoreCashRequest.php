<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('manage cashes');
    }

    public function rules(): array
    {
        // Obtiene el ID del tenant para las validaciones
        $tenantId = Auth::user()->tenant_id;

        return [
            'name'       => [
                'required',
                'string',
                'max:100',
                // Asegura que el nombre de la caja sea único dentro del tenant
                Rule::unique('cashes')->where(fn($query) => $query->where('tenant_id', $tenantId)),
            ],
            // Debe ser una cuenta contable existente y de tipo 'CASH' para este tenant
            'account_id' => [
                'required',
                'exists:accounts,id',
                Rule::exists('accounts', 'id')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId)
                        ->where('type', 'CASH');
                }),
            ],
        ];
    }
}
