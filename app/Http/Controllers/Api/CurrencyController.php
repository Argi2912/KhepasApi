<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Http\Requests\StoreCurrencyRequest;
use App\Http\Requests\UpdateCurrencyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class CurrencyController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage exchange rates');
    }

    /**
     * Muestra la lista de divisas.
     */
    public function index(Request $request): JsonResponse
    {
        // Si no se pide paginación, devolver listado para dropdowns
        if ($request->missing('page') && $request->missing('per_page')) {
            return response()->json(
                Currency::where('is_active', true)
                       ->orderBy('is_base', 'desc')
                       ->orderBy('name', 'asc')
                       ->get()
            );
        }

        // Paginado para el CRUD
        $query = Currency::latest();

        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where('name', 'like', $searchTerm)
                  ->orWhere('code', 'like', $searchTerm)
                  ->orWhere('symbol', 'like', $searchTerm);
        }
        
        $perPage = $request->get('per_page', 20);
        $currencies = $query->paginate($perPage);
        
        return response()->json($currencies);
    }

    /**
     * Almacena una nueva divisa.
     */
    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        // LÓGICA DE NEGOCIO: Si esta es la nueva base, desmarcar la anterior.
        if (isset($validatedData['is_base']) && $validatedData['is_base'] === true) {
            Currency::where('is_base', true)->update(['is_base' => false]);
        }

        $currency = Currency::create($validatedData);
        return response()->json(['message' => 'Divisa creada exitosamente.', 'currency' => $currency], 201);
    }

    /**
     * Muestra una divisa específica.
     */
    public function show(Currency $currency): JsonResponse
    {
        return response()->json($currency);
    }

    /**
     * Actualiza una divisa.
     */
    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $validatedData = $request->validated();

        // LÓGICA DE NEGOCIO: Si esta es la nueva base, desmarcar la anterior.
        if (isset($validatedData['is_base']) && $validatedData['is_base'] === true) {
            // Desmarcar cualquier otra moneda que sea base
            Currency::where('is_base', true)
                    ->where('id', '!=', $currency->id)
                    ->update(['is_base' => false]);
        }

        $currency->update($validatedData);
        return response()->json(['message' => 'Divisa actualizada.', 'currency' => $currency]);
    }

    /**
     * Elimina una divisa.
     */
    public function destroy(Currency $currency): JsonResponse
    {
        // VALIDACIÓN: No se puede eliminar la moneda base
        if ($currency->is_base) {
            return response()->json(['message' => 'No se puede eliminar la divisa base del sistema.'], 422);
        }

        // VALIDACIÓN: No se puede eliminar si está en uso (Ej. en Exchange Rates o Cajas)
        // (Asumiendo que ExchangeRate tiene relaciones 'fromCurrency' y 'toCurrency')
        if ($currency->exchangeRatesFrom()->exists() || $currency->exchangeRatesTo()->exists()) {
             return response()->json(['message' => 'No se puede eliminar: La divisa está siendo usada en tasas de cambio.'], 422);
        }
        
        $currency->delete();
        return response()->json(['message' => 'Divisa eliminada.']);
    }
}
