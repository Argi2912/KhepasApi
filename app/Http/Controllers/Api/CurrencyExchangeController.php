<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchange;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

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
                'toAccount:id,name,currency_code'
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
            'operation_type'        => ['required', Rule::in(['purchase', 'exchange'])],
            'client_id'             => 'required|exists:clients,id',
            'broker_id'             => 'nullable|exists:brokers,id',
            'provider_id'           => 'nullable|exists:providers,id',
            'admin_user_id'         => 'required|exists:users,id',
            'from_account_id'       => 'required|exists:accounts,id',
            'to_account_id'         => 'required|exists:accounts,id',
            'amount_sent'           => 'required|numeric|min:0.01',
            'amount_received'       => 'required|numeric|min:0.01',
            'commission_charged_pct'  => 'nullable|numeric|min:0',
            'commission_provider_pct' => 'nullable|numeric|min:0',
            
            // 🚨 NUEVO: Validación para porcentaje del Broker
            'commission_broker_pct'   => 'nullable|numeric|min:0', 

            'reference_id'          => 'nullable|string|max:255',
            'delivered'             => 'sometimes|boolean', 
        ];

        if ($request->operation_type === 'exchange') {
            $rules['exchange_rate'] = 'required|numeric|min:0.00000001';
            $rules['platform_id']   = 'required|exists:platforms,id';
            $rules['commission_admin_pct'] = 'nullable|numeric|min:0';
        } else {
            $rules['buy_rate']      = 'required|numeric|min:0.00000001';
            $rules['received_rate'] = 'required|numeric|min:0.00000001';
            $rules['platform_id']   = 'nullable|exists:platforms,id';
        }

        $validatedData = $request->validate($rules);

        $dataToService = $validatedData;

        // Mapeo de datos opcionales
        $dataToService['buy_rate'] = $request->get('buy_rate', null);
        $dataToService['received_rate'] = $request->get('received_rate', null);
        $dataToService['platform_id'] = $request->get('platform_id', null);
        
        // Porcentajes
        $dataToService['commission_admin_pct'] = $request->get('commission_admin_pct', 0);
        $dataToService['commission_broker_pct'] = $request->get('commission_broker_pct', 0); // 🚨 NUEVO

        // Montos Monetarios (Calculados en Frontend)
        // Nota: commission_charged_amount del front se guarda como commission_total_amount en BD
        $dataToService['commission_total_amount'] = $request->get('commission_charged_amount', 0);
        $dataToService['commission_provider_amount'] = $request->get('commission_provider_amount', 0);
        $dataToService['commission_admin_amount'] = $request->get('commission_admin_amount', 0);
        
        // 🚨 NUEVO: Capturar el monto del Broker para pasarlo al servicio
        $dataToService['commission_broker_amount'] = $request->get('commission_broker_amount', 0);

        // Estado según entrega física (solo en compras)
        if ($request->operation_type === 'purchase') {
            $dataToService['status'] = $request->boolean('delivered', true) ? 'completed' : 'pending';
            $dataToService['exchange_rate'] = $dataToService['received_rate'];
            // $dataToService['buy_rate'] ya está asignado arriba
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

    // NUEVO: Marcar como entregada
    public function markDelivered(CurrencyExchange $exchange)
    {
        if (!$exchange->buy_rate || $exchange->buy_rate <= 0) {
            return response()->json(['message' => 'Solo aplicable a compras de divisa'], 400);
        }

        if ($exchange->status === 'completed') {
            return response()->json(['message' => 'Ya está marcada como entregada'], 400);
        }

        $user = Auth::user();
        $user->load('roles'); 

        $userRoles = $user->roles->pluck('name')->toArray();
        
        $allowedRoles = ['admin', 'cashier', 'admin_tenant', 'superadmin'];
        if (empty(array_intersect($userRoles, $allowedRoles))) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'debug_roles_detected' => $userRoles 
            ], 403);
        }

        $exchange->status = 'completed';
        $exchange->save();

        return response()->json(['message' => 'Transacción marcada como entregada']);
    }

    public function show($id)
    {
        $tx = CurrencyExchange::withoutGlobalScopes()->find($id);

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $user = auth()->user();
        if ($tx->tenant_id != $user->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $client = \App\Models\Client::withoutGlobalScopes()->find($tx->client_id);
        $broker = \App\Models\Broker::withoutGlobalScopes()->find($tx->broker_id);
        $brokerUser = $broker ? \App\Models\User::withoutGlobalScopes()->find($broker->user_id) : null;
        if ($broker && $brokerUser) $broker->setRelation('user', $brokerUser);
        $provider = \App\Models\Provider::withoutGlobalScopes()->find($tx->provider_id);
        $admin = \App\Models\User::withoutGlobalScopes()->find($tx->admin_user_id);
        $fromAccount = \App\Models\Account::withoutGlobalScopes()->find($tx->from_account_id);
        $toAccount = \App\Models\Account::withoutGlobalScopes()->find($tx->to_account_id);

        $tx->setRelation('client', $client);
        $tx->setRelation('broker', $broker);
        $tx->setRelation('provider', $provider);
        $tx->setRelation('adminUser', $admin);
        $tx->setRelation('fromAccount', $fromAccount);
        $tx->setRelation('toAccount', $toAccount);

        return response()->json($tx);
    }
}