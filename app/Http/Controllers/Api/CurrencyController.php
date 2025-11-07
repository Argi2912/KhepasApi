<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $validated = $request->validate([
            // La validación se hace contra la clave primaria 'code'
            'code' => ['required', 'string', 'bail', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:50', 'bail', Rule::unique('currencies', 'name')],
        ]);

        $currency = Currency::create($validated);

        return response()->json($currency, 201);
    }

    public function show(Currency $currency)
    {
        return response()->json($currency);
    }

    public function update(Request $request, Currency $currency)
    {
        $validated = $request->validate([
            // Solo se permite actualizar el nombre
            'name' => ['required', 'string', 'max:50', Rule::unique('currencies', 'name')->ignore($currency->code, 'code')],
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
