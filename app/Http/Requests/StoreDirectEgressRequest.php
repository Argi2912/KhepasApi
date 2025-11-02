<?php

namespace App\Http\Requests;

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
        // Se añade el nombre de la cuenta de caja para que el Controller lo use
        $cash = \App\Models\Cash::findOrFail($this->cash_id);
        $this->merge(['cash_account_name' => $cash->account->name]);
    }
}