<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService; // <-- Se inyecta el Servicio
use Illuminate\Http\Request;
// Reemplaza con StoreExchangeRateRequest

class ExchangeRateController extends Controller
{
    protected $exchangeRateService;

    // Inyección de dependencias del servicio
    public function __construct(ExchangeRateService $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'is_active'     => 'nullable|boolean', // 🚨 Aceptar el filtro
            'from_currency' => 'nullable|string',
            'to_currency'   => 'nullable|string',
            'start_date'    => 'nullable|date_format:Y-m-d',
            'end_date'      => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $query = ExchangeRate::query();

        // 🚨 CORRECCIÓN: Aplicar el filtro 'is_active'
        // Esto es lo que soluciona el problema de "TASA NO DISPONIBLE"
        $query->when($request->boolean('is_active'), function ($q) {
            return $q->where('is_active', true);
        });

        $query->when($request->from_currency, fn($q, $code) => $q->fromCurrency($code));
        $query->when($request->to_currency, fn($q, $code) => $q->toCurrency($code));
        $query->when($request->start_date, fn($q, $date) => $q->fromDate($date));
        $query->when($request->end_date, fn($q, $date) => $q->toDate($date));

        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request) // Usa StoreExchangeRateRequest
    {
        $validated = $request->validate([
            'from_currency' => 'required|string',
            'to_currency'   => 'required|string',
            'rate'          => 'required|numeric|min:0.00000001',
        ]);

        // Delega la lógica (incluyendo crear la tasa inversa) al servicio
        $rate = $this->exchangeRateService->createRate($validated);

        return response()->json($rate, 201);
    }

    public function show(ExchangeRate $exchangeRate)
    {
        return $exchangeRate;
    }

    // update y destroy se omiten por simplicidad,
    // ya que las tasas suelen registrarse, no editarse.
    // Si se editan, el ActivityLog registrará el cambio.
}
