<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Http\Requests\StoreExchangeRateRequest;
use App\Http\Requests\UpdateExchangeRateRequest;
use App\Models\ExchangeTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon; // Importar Carbon

class ExchangeRateController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage exchange rates');
    }

    // ... (index, store, show, update, destroy se mantienen igual) ...
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
        
        $perPage = $request->get('per_page', 20);
        $rates = $query->paginate($perPage);
        
        return response()->json($rates);
    }

    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $rate = ExchangeRate::create($request->validated());
        return response()->json(['message' => 'Tasa de cambio registrada.', 'rate' => $rate], 201);
    }

    public function show(ExchangeRate $exchangeRate): JsonResponse
    {
        return response()->json($exchangeRate->load(['fromCurrency', 'toCurrency']));
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $exchangeRate->update($request->validated());
        return response()->json(['message' => 'Tasa de cambio actualizada.', 'rate' => $exchangeRate]);
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        if (ExchangeTransaction::where('exchange_rate_id', $exchangeRate->id)->exists()) {
             return response()->json([
               'message' => 'No se puede eliminar: La tasa está siendo usada en operaciones de intercambio.'
             ], 422);
        }
        
        $exchangeRate->delete();
        return response()->json(['message' => 'Tasa de cambio eliminada.']);
    }


    /**
     * Obtiene la tasa de cambio más reciente para un par de divisas y una fecha.
     * (MODIFICADO PARA INCLUIR LA FECHA)
     */
    public function getLatestRate(Request $request): \Illuminate\Http\JsonResponse
    {
        // --- INICIO DE LA CORRECCIÓN ---
        // 1. Añadir 'date' a la validación
        $validated = $request->validate([
            'from_currency_id' => 'required|exists:currencies,id',
            'to_currency_id'   => 'required|exists:currencies,id|different:from_currency_id',
            'date'             => 'required|date_format:Y-m-d', // Validar la fecha
        ]);

        $rate = ExchangeRate::where('tenant_id', Auth::user()->tenant_id)
                            ->where('from_currency_id', $validated['from_currency_id'])
                            ->where('to_currency_id', $validated['to_currency_id'])
                            // 2. Usar la fecha para buscar la tasa correcta
                            ->whereDate('date', '<=', $validated['date']) 
                            ->latest('date') // La más reciente (en o antes de la fecha dada)
                            ->with(['fromCurrency', 'toCurrency']) 
                            ->first();
        // --- FIN DE LA CORRECCIÓN --- (Esta es la línea 84-91 aprox)

        if (!$rate) {
            return response()->json(['message' => 'No se encontró una tasa de cambio para este par en la fecha seleccionada (o anterior).'], 404);
        }

        return response()->json($rate);
    }
}