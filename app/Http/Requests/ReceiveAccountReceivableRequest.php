<?php

namespace App\Http\Requests;

use App\Models\Cash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;

class ReceiveAccountReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo administradores y personal autorizado (con permiso 'receive cxc payment')
        return Auth::check() && Auth::user()->can('receive cxc payment');
    }

    public function rules(): array
    {
        return [
            // ID de la transacción CXC original que se va a saldar
            'cxc_transaction_id' => [
                'required', 
                'exists:transactions,id',
                // Validación personalizada para asegurar que es una CXC PENDIENTE del mismo tenant
                function ($attribute, $value, $fail) {
                    $transaction = Transaction::where('id', $value)
                                              ->where('tenant_id', Auth::user()->tenant_id)
                                              ->first();
                    
                    if (!$transaction) {
                        return $fail('La transacción CXC no existe o no pertenece a su tenant.');
                    }
                    if ($transaction->status !== 'PENDING') {
                        return $fail('La CXC seleccionada ya fue saldada o cancelada.');
                    }
                }
            ],
            // Monto del cobro (debe ser menor o igual al monto pendiente)
            'amount' => ['required', 'numeric', 'min:0.01'],
            // Caja/Plataforma a la que entra el dinero
            'cash_id' => ['required', 'exists:cashes,id'],
        ];
    }
    
    public function passedValidation()
    {
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