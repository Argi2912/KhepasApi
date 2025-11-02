<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo usuarios con permiso para 'manage users' pueden usar esta ruta
        return Auth::check() && Auth::user()->can('manage users');
    }

    public function rules(): array
    {
        // Roles que un Tenant Admin puede asignar a usuarios de su propio Tenant
        $allowedRoles = ['Tenant Admin', 'Broker', 'Client', 'Provider']; 

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // El email debe ser único en la tabla 'users'
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            
            // El rol debe ser uno de los permitidos
            'role_name' => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }
}