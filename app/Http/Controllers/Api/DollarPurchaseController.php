<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DollarPurchase;
use App\Services\TransactionService; // <-- Usa el mismo servicio
use Illuminate\Http\Request;
// Reemplaza con StoreDollarPurchaseRequest

class DollarPurchaseController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request) // 2. Inyectar Request
    {
        // 3. Validar
        $request->validate([
            'client_id'     => 'nullable|integer|exists:clients,id',
            'broker_id'     => 'nullable|integer|exists:brokers,id',
            'provider_id'   => 'nullable|integer|exists:providers,id',
            'admin_user_id' => 'nullable|integer|exists:users,id',
            'start_date'    => 'nullable|date_format:Y-m-d',
            'end_date'      => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 4. Iniciar consulta
        $query = DollarPurchase::query()
            ->with('client:id,name', 'broker', 'provider:id,name', 'platformAccount:id,name');

        // 5. Aplicar scopes
        $query->when($request->client_id, fn($q, $id) => $q->clientId($id));
        $query->when($request->broker_id, fn($q, $id) => $q->brokerId($id));
        $query->when($request->provider_id, fn($q, $id) => $q->providerId($id));
        $query->when($request->admin_user_id, fn($q, $id) => $q->adminUserId($id));
        $query->when($request->start_date, fn($q, $id) => $q->fromDate($id));
        $query->when($request->end_date, fn($q, $id) => $q->toDate($id));

        // 6. Paginar
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request) // Usa StoreDollarPurchaseRequest
    {
        $validatedData = $request->validate([
            'client_id'               => 'required|exists:clients,id',
            'broker_id'               => 'required|exists:brokers,id',
            'provider_id'             => 'required|exists:providers,id',
            'admin_user_id'           => 'required|exists:users,id',
            'platform_account_id'     => 'required|exists:accounts,id',
            'from_account_id'         => 'required|exists:accounts,id', // <-- 🚨 AÑADIDO
            'amount_received'         => 'required|numeric|min:0.01',
            'deliver_currency_code' => 'required|string|size:3',
            'buy_rate'                => 'required|numeric|min:0',
            'received_rate'           => 'required|numeric|min:0',
            'commission_charged_pct'  => 'required|numeric|min:0',
            'commission_provider_pct' => 'required|numeric|min:0',
        ]);

        try {
            $transaction = $this->transactionService->createDollarPurchase($validatedData);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al procesar la transacción', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(DollarPurchase $dollarPurchase)
    {
        return $dollarPurchase->load('client', 'broker', 'provider', 'admin', 'platformAccount', 'ledgerEntries');
    }
}
