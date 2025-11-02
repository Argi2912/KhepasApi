<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreAccountPayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorización de Laravel Permission
        return Auth::check() && Auth::user()->can('register cxp');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            // Asegura que el cliente/proveedor al que le debo exista
            'provider_user_id' => ['required', 'exists:users,id'], 
        ];
    }
}