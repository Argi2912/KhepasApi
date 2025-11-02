<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('manage exchange rates');
    }

    public function rules(): array
    {
        return [
            'rate' => ['required', 'numeric', 'min:0.000001'],
            // Las IDs de moneda y la fecha generalmente no se cambian en una actualización
        ];
    }
}