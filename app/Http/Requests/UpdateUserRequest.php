<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo usuarios con permiso para 'manage users' pueden usar esta ruta
        return Auth::check() && Auth::user()->can('manage users');
    }

    public function rules(): array
    {
        // Obtener el ID del usuario que se está actualizando
        $userId = $this->route('user')->id;
        $allowedRoles = ['Tenant Admin', 'Broker', 'Client', 'Provider']; 

        return [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            // El email es opcional, pero si se envía, debe ser único, excluyendo el email actual del usuario.
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            
            // El rol es opcional, pero si se envía, debe ser uno de los permitidos
            'role_name' => ['nullable', 'string', Rule::in($allowedRoles)],
        ];
    }
}