<?php

namespace App\Http\Requests;

use App\Models\Cash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreDirectEgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo administradores y personal autorizado pueden registrar egresos
        return Auth::check() && Auth::user()->can('register direct egress');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            // ID de la caja/plataforma de la que sale el dinero
            'cash_id' => ['required', 'exists:cashes,id'],
        ];
    }
    
    public function passedValidation()
    {
        // --- SOLUCIÓN: Usar with('account') ---
        $cash = Cash::with('account')->findOrFail($this->cash_id);
        
        // La relación 'account' debe existir porque la cuenta de la caja es obligatoria.
        // Verificamos que no sea nulo antes de acceder a 'name'.
        if (is_null($cash->account)) {
             // Esto no debería pasar con la validación Rule::exists, pero es un seguro.
             throw new \Exception("La caja seleccionada no tiene una cuenta contable asociada.");
        }
        
        $this->merge(['cash_account_name' => $cash->account->name]);
    }
}