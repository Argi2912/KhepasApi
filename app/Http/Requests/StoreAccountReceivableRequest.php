<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreAccountReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo administradores y personal autorizado (con permiso 'register cxc') pueden registrar CXC
        return Auth::check() && Auth::user()->can('register cxc');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            // ID del cliente/persona que debe
            'customer_user_id' => ['nullable', 'exists:users,id'], 
        ];
    }
}