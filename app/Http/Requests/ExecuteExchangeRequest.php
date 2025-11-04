<?php

namespace App\Http\Requests;

use App\Models\Cash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\ExchangeRate;
use App\Models\Currency;

class ExecuteExchangeRequest extends FormRequest
{
    private $cashGiven;
    private $cashReceived;

    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('execute currency exchange');
    }

    public function rules(): array
    {
       return [
            'cash_given_id' => ['required', 'exists:cashes,id'],
            'cash_received_id' => ['required', 'exists:cashes,id', 'different:cash_given_id'],
            'amount_given' => ['required', 'numeric', 'min:0.01'],
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ];
    }
    
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            $this->cashGiven = Cash::with('currency')->find($this->cash_given_id);
            $this->cashReceived = Cash::with('currency')->find($this->cash_received_id);

            if (!$this->cashGiven || !$this->cashGiven->currency) {
                $validator->errors()->add('cash_given_id', 'La caja de origen no existe o no tiene una divisa asignada.');
                return;
            }
            if (!$this->cashReceived || !$this->cashReceived->currency) {
                $validator->errors()->add('cash_received_id', 'La caja de destino no existe o no tiene una divisa asignada.');
                return;
            }
            if ($this->cashGiven->currency_id === $this->cashReceived->currency_id) {
                 $validator->errors()->add('cash_received_id', 'No se puede intercambiar a una caja de la misma divisa.');
            }

            $totalDebit = $this->amount_given + ($this->fee ?? 0);
            if ($this->cashGiven->balance < $totalDebit) {
                $validator->errors()->add('amount_given', 'Fondos insuficientes en la caja de origen (incluyendo comisión).');
            }
            
            $tenantId = Auth::user()->tenant_id;
            
            // Usamos la lógica correcta de buscar la tasa MÁS RECIENTE
            $rate = ExchangeRate::where('tenant_id', $tenantId)
                                ->where('from_currency_id', $this->cashGiven->currency_id)
                                ->where('to_currency_id', $this->cashReceived->currency_id)
                                ->latest('date') 
                                ->first(); 
            
            if (!$rate) {
                $validator->errors()->add('exchange_rate', 'No existe una tasa de cambio oficial registrada para este par de divisas.');
            } else {
                // Inyectamos los 3 valores que el controlador necesita
                $this->merge([
                    'exchange_rate_object' => $rate,
                    'currency_given_id' => $this->cashGiven->currency_id,
                    'currency_received_id' => $this->cashReceived->currency_id,
                ]);
            }
        });
    }
}