<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchange;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CurrencyExchangeController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'client_id'     => 'nullable|integer',
            'broker_id'     => 'nullable|integer',
            'admin_user_id' => 'nullable|integer',
            'status'        => 'nullable|string|in:pending,completed',
            'start_date'    => 'nullable|date_format:Y-m-d',
            'end_date'      => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $query = CurrencyExchange::query()
            ->with([
                'client:id,name',
                'broker.user:id,name',
                'adminUser:id,name',
                'fromAccount:id,name,currency_code',
                'toAccount:id,name,currency_code',
                'investor:id,name,alias', // Cargar datos del inversionista si existe
            ]);

        $query->when($request->client_id, fn($q, $id) => $q->clientId($id));
        $query->when($request->broker_id, fn($q, $id) => $q->brokerId($id));
        $query->when($request->admin_user_id, fn($q, $id) => $q->adminUserId($id));
        $query->when($request->status, fn($q, $status) => $q->where('status', $status));
        $query->when($request->start_date, fn($q, $date) => $q->fromDate($date));
        $query->when($request->end_date, fn($q, $date) => $q->toDate($date));

        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $rules = [
            'operation_type'          => ['required', Rule::in(['purchase', 'exchange'])],
            'client_id'               => 'required|exists:clients,id',
            'broker_id'               => 'nullable|exists:brokers,id',
            'provider_id'             => 'nullable|exists:providers,id',
            'admin_user_id'           => 'required|exists:users,id',
            'from_account_id'         => [
                'nullable',
                Rule::requiredIf($request->input('capital_type') !== 'investor'),
                'integer',
            ],
            'to_account_id'           => 'required|exists:accounts,id',
            'amount_sent'             => 'required|numeric|min:0.01',
            'amount_received'         => 'required|numeric|min:0.01',

            // Porcentajes de comisiones
            'commission_charged_pct'  => 'nullable|numeric|min:0',
            'commission_provider_pct' => 'nullable|numeric|min:0',
            'commission_broker_pct'   => 'nullable|numeric|min:0',

            // Referencia y Entrega
            'reference_id'            => 'nullable|string|max:255',
            'delivered'               => 'sometimes|boolean',

            // --- NUEVOS CAMPOS: CAPITAL DE TERCERO ---
            'capital_type'            => 'required|in:own,investor',
            // El inversionista es obligatorio solo si el tipo de capital es 'investor'
            'investor_id'             => 'required_if:capital_type,investor|nullable|exists:investors,id',
            'investor_profit_pct'     => 'nullable|numeric|min:0',
            'investor_profit_amount'  => 'nullable|numeric|min:0',
        ];

        // Reglas condicionales según tipo de operación
        if ($request->operation_type === 'exchange') {
            $rules['exchange_rate']        = 'required|numeric|min:0.00000001';
            $rules['platform_id']          = 'required|exists:platforms,id';
            $rules['commission_admin_pct'] = 'nullable|numeric|min:0';
        } else {
            $rules['buy_rate']      = 'required|numeric|min:0.00000001';
            $rules['received_rate'] = 'required|numeric|min:0.00000001';
            $rules['platform_id']   = 'nullable|exists:platforms,id';
        }

        $validatedData = $request->validate($rules);

        $dataToService = $validatedData;

        // Mapeo de datos opcionales
        $dataToService['buy_rate']      = $request->get('buy_rate', null);
        $dataToService['received_rate'] = $request->get('received_rate', null);
        $dataToService['platform_id']   = $request->get('platform_id', null);

        // Porcentajes
        $dataToService['commission_admin_pct']  = $request->get('commission_admin_pct', 0);
        $dataToService['commission_broker_pct'] = $request->get('commission_broker_pct', 0);

        // Montos Monetarios (Calculados en Frontend)
        $dataToService['commission_total_amount']    = $request->get('commission_charged_amount', 0);
        $dataToService['commission_provider_amount'] = $request->get('commission_provider_amount', 0);
        $dataToService['commission_admin_amount']    = $request->get('commission_admin_amount', 0);
        $dataToService['commission_broker_amount']   = $request->get('commission_broker_amount', 0);

        // --- MAPEO DE INVERSIONISTA ---
        $dataToService['capital_type']           = $request->get('capital_type', 'own');
        $dataToService['investor_id']            = $request->get('investor_id', null);
        $dataToService['investor_profit_pct']    = $request->get('investor_profit_pct', 0);
        $dataToService['investor_profit_amount'] = $request->get('investor_profit_amount', 0);

        // Estado según entrega física
        if ($request->operation_type === 'purchase') {
            $dataToService['status']        = $request->boolean('delivered', true) ? 'completed' : 'pending';
            $dataToService['exchange_rate'] = $dataToService['received_rate'];
        } else {
            $dataToService['status'] = 'completed';
        }

        try {
            $transaction = $this->transactionService->createCurrencyExchange($dataToService);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al procesar la transacción', 'error' => $e->getMessage()], 400);
        }
    }

    public function markDelivered(CurrencyExchange $exchange)
    {
        if (! $exchange->buy_rate || $exchange->buy_rate <= 0) {
            return response()->json(['message' => 'Solo aplicable a compras de divisa'], 400);
        }

        if ($exchange->status === 'completed') {
            return response()->json(['message' => 'Ya está marcada como entregada'], 400);
        }

        $user = Auth::user();
        $user->load('roles');

        $userRoles    = $user->roles->pluck('name')->toArray();
        $allowedRoles = ['admin', 'cashier', 'admin_tenant', 'superadmin'];

        if (empty(array_intersect($userRoles, $allowedRoles))) {
            return response()->json(['message' => 'No tienes permiso.'], 403);
        }

        $exchange->status = 'completed';
        $exchange->save();

        return response()->json(['message' => 'Transacción marcada como entregada']);
    }

    public function show($id)
    {
        $tx = CurrencyExchange::withoutGlobalScopes()->find($id);

        if (! $tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $user = auth()->user();
        if ($tx->tenant_id != $user->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Carga de relaciones
        $tx->load([
            'client',
            'broker.user',
            'provider',
            'adminUser',
            'fromAccount',
            'toAccount',
            'investor', // Cargar al inversionista si existe
        ]);

        return response()->json($tx);
    }
}
