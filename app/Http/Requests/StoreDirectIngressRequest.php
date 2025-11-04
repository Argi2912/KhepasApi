<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreDirectIngressRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo administradores y personal autorizado pueden registrar ingresos
        return Auth::check() && Auth::user()->can('register direct ingress');
    }

    public function rules(): array
    {
        return [
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            // ID de la caja/plataforma a la que entra el dinero (Ej: Binance, Zelle)
            'cash_id'     => ['required', 'exists:cashes,id'],
        ];
    }

    public function passedValidation()
    {
        // Se añade el nombre de la cuenta de caja para que el Controller lo use
        $cash = \App\Models\Cash::with('account')->findOrFail($this->cash_id);
        if (is_null($cash->account)) {
             // Esto no debería pasar con la validación Rule::exists, pero es un seguro.
             throw new \Exception("La caja seleccionada no tiene una cuenta contable asociada.");
        }
        $this->merge(['cash_account_name' => $cash->account->name]);
    }
}
