<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Asumo que el permiso necesario es 'manage accounts' o similar
        return Auth::check() && Auth::user()->can('manage accounts');
    }

    public function rules(): array
    {
        $tenantId = Auth::user()->tenant_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // El nombre debe ser único dentro del tenant
                Rule::unique('accounts')->where(fn($query) => $query->where('tenant_id', $tenantId)),
            ],
            'type' => [
                'required',
                'string',
                // Validamos contra los tipos definidos en tu migración ENUM
                Rule::in(['CASH', 'CXC', 'CXP', 'INGRESS', 'EGRESS', 'EQUITY', 'ASSET', 'LIABILITY']),
            ],
            'is_system' => 'boolean', // Si es una cuenta predefinida por el sistema
            'is_active' => 'boolean',
        ];
    }
}