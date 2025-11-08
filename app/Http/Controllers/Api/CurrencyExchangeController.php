<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchange;
use App\Services\TransactionService; // <-- Se inyecta el Servicio
use Illuminate\Http\Request; // Reemplaza con StoreCurrencyExchangeRequest

class CurrencyExchangeController extends Controller
{
    protected $transactionService;

    // Inyección de dependencias
    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request) // 2. Inyectar Request
    {
        // 3. Validar todos los filtros posibles
        $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
            'broker_id' => 'nullable|integer|exists:brokers,id',
            'provider_id' => 'nullable|integer|exists:providers,id',
            'admin_user_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 4. Iniciar consulta con relaciones
        $query = CurrencyExchange::query()
            ->with('client:id,name', 'broker', 'fromAccount:id,name', 'toAccount:id,name');

        // 5. Aplicar todos los scopes dinámicamente
        $query->when($request->client_id, function ($q, $id) {
            return $q->clientId($id);
        });

        $query->when($request->broker_id, function ($q, $id) {
            return $q->brokerId($id);
        });

        $query->when($request->provider_id, function ($q, $id) {
            return $q->providerId($id);
        });

        $query->when($request->admin_user_id, function ($q, $id) {
            return $q->adminUserId($id);
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        // 6. Paginar
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request) // Usa StoreCurrencyExchangeRequest
    {
        // La validación (que los IDs existan, etc.) debe ir en un Form Request
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'broker_id' => 'required|exists:brokers,id',
            'provider_id' => 'required|exists:providers,id',
            'admin_user_id' => 'required|exists:users,id',
            'from_account_id' => 'required|exists:accounts,id',
            'to_account_id' => 'required|exists:accounts,id',
            'amount_received' => 'required|numeric|min:0.01',
            'commission_charged_pct' => 'required|numeric|min:0',
            'commission_provider_pct' => 'required|numeric|min:0',
            'commission_admin_pct' => 'required|numeric|min:0',
        ]);
        
        try {
            // Delega toda la lógica compleja al servicio
            $transaction = $this->transactionService->createCurrencyExchange($validatedData);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al procesar la transacción', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(CurrencyExchange $currencyExchange)
    {
        // Carga todas las relaciones al mostrar uno
        return $currencyExchange->load('client', 'broker', 'provider', 'admin', 'fromAccount', 'toAccount', 'ledgerEntries');
    }
}