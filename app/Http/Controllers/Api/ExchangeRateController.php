<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Http\Requests\StoreExchangeRateRequest;
use App\Http\Requests\UpdateExchangeRateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function __construct()
    {
        // Solo usuarios con permiso para gestionar tasas pueden acceder
        $this->middleware('permission:manage exchange rates');
    }

    /**
     * Muestra una lista de tasas de cambio históricas y activas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExchangeRate::with(['fromCurrency', 'toCurrency'])
                               ->latest('date');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }
        
        if ($request->filled('from_currency_id')) {
            $query->where('from_currency_id', $request->from_currency_id);
        }
        
        // La búsqueda tipo datatable puede ser más compleja, pero este filtro es clave
        
        $perPage = $request->get('per_page', 20);
        $rates = $query->paginate($perPage);
        
        return response()->json($rates);
    }

    /**
     * Almacena una nueva tasa de cambio.
     */
    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $rate = ExchangeRate::create($request->validated());
        return response()->json(['message' => 'Tasa de cambio registrada.', 'rate' => $rate], 201);
    }

    /**
     * Muestra el detalle de una tasa específica.
     */
    public function show(ExchangeRate $exchangeRate): JsonResponse
    {
        return response()->json($exchangeRate->load(['fromCurrency', 'toCurrency']));
    }

    /**
     * Actualiza la tasa de cambio (generalmente solo el valor 'rate').
     */
    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $exchangeRate->update($request->validated());
        return response()->json(['message' => 'Tasa de cambio actualizada.', 'rate' => $exchangeRate]);
    }

    /**
     * Elimina una tasa de cambio.
     */
    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        // Se debe verificar si esta tasa fue usada en una ExchangeTransaction
        $exchangeRate->delete();
        return response()->json(['message' => 'Tasa de cambio eliminada.']);
    }
}