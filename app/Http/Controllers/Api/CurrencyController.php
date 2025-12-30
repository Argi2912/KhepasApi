<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class CurrencyController extends Controller
{
    public function index(Request $request)
    {
        $currencies = Currency::query()
            ->when($request->search, function ($query, $search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
            ->orderBy('code', 'asc')
            ->paginate(15)
            ->withQueryString();

        return response()->json($currencies);
    }

    public function store(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:5',
                Rule::unique('currencies')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('currencies')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ]);

        // Aseguramos que se guarde con el tenant_id
        $validated['tenant_id'] = $tenantId;

        $currency = Currency::create($validated);

        return response()->json($currency, 201);
    }

    public function show(Currency $currency)
    {
        return response()->json($currency);
    }

    public function update(Request $request, Currency $currency)
    {
        $tenantId = Auth::user()->tenant_id;

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:5',
                Rule::unique('currencies')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
            'name' => [
                'required',
                'string',
                'max:50',
                // Ignoramos la divisa actual ($currency->id) pero validamos en el tenant
                Rule::unique('currencies')->ignore($currency->id)->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ]);

        $currency->update($validated);

        return response()->json($currency);
    }

    public function destroy(Currency $currency)
    {
        // 🚨 Impedir la eliminación si la divisa está en uso
        if ($currency->accounts()->exists() || $currency->exchangeRatesFrom()->exists() || $currency->exchangeRatesTo()->exists()) {
            return response()->json(['message' => 'No se puede eliminar la divisa. Está en uso por cuentas, tasas o transacciones.'], 409);
        }

        $currency->delete();

        return response()->json(null, 204);
    }
}
