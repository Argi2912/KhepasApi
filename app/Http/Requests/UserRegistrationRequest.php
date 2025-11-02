<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRegistrationRequest extends FormRequest
{
    /**
     * Permite que todos los usuarios (incluso sin autenticar) accedan a esta ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            
            // Requerimientos robustos
            'phone_number' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            
            // Campo clave para multi-tenancy: Un usuario debe saber a qué tenant registrarse
            'tenant_id' => ['required', 'exists:tenants,id'],
            
            // El rol predeterminado es 'Client'. Si un Admin registra a alguien,
            // podría pasar este campo, pero lo restringimos para el registro público.
            'role_name' => [
                'nullable', 
                'string',
                Rule::in(['Client']), // Solo se permite registrarse como 'Client' públicamente
            ],
        ];
    }
}