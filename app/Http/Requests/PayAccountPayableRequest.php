<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;

class PayAccountPayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo administradores y personal autorizado (con permiso 'pay cxp debt')
        return Auth::check() && Auth::user()->can('pay cxp debt');
    }

    public function rules(): array
    {
        return [
            // ID de la transacción CXP original que se va a saldar
            'cxp_transaction_id' => [
                'required', 
                'exists:transactions,id',
                // Validación personalizada para asegurar que es una CXP PENDIENTE del mismo tenant
                function ($attribute, $value, $fail) {
                    $transaction = Transaction::where('id', $value)
                                              ->where('tenant_id', Auth::user()->tenant_id)
                                              ->first();
                    
                    if (!$transaction) {
                        return $fail('La transacción CXP no existe o no pertenece a su tenant.');
                    }
                    if ($transaction->status !== 'PENDING') {
                        return $fail('La CXP seleccionada ya fue saldada o cancelada.');
                    }
                }
            ],
            // Monto del pago (debe ser menor o igual al monto pendiente)
            'amount' => ['required', 'numeric', 'min:0.01'],
            // Caja/Plataforma de donde sale el dinero
            'cash_id' => ['required', 'exists:cashes,id'],
        ];
    }
    
    public function passedValidation()
    {
        // Añade el nombre de la cuenta de caja para el Controller
        $cash = \App\Models\Cash::findOrFail($this->cash_id);
        $this->merge(['cash_account_name' => $cash->account->name]);
    }
}