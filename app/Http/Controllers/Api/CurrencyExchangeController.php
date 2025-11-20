<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchange;
use App\Services\TransactionService;
use Illuminate\Http\Request;
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
            'status'        => 'nullable|string',
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

        // Filtros Dinámicos
        $query->when($request->client_id, fn($q, $id) => $q->clientId($id));
        $query->when($request->broker_id, fn($q, $id) => $q->brokerId($id));
        $query->when($request->admin_user_id, fn($q, $id) => $q->adminUserId($id));
        $query->when($request->status, fn($q, $status) => $q->where('status', $status));
        $query->when($request->start_date, fn($q, $date) => $q->fromDate($date));
        $query->when($request->end_date, fn($q, $date) => $q->toDate($date));

        return $query->latest()->paginate(15)->withQueryString();
    }


    /**
     * Almacena una nueva transacción (Unificada para Compra y Cambio).
     */
    public function store(Request $request)
    {
        // 1. Validaciones Comunes
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
            
            // Comisiones (en porcentaje - siempre se envían)
            'commission_charged_pct'  => 'nullable|numeric|min:0',
            'commission_provider_pct' => 'nullable|numeric|min:0',
            
            'reference_id'          => 'nullable|string|max:255',
        ];

        // 2. Validaciones Condicionales
        if ($request->operation_type === 'exchange') {
            $rules['exchange_rate'] = 'required|numeric|min:0.00000001'; 
            $rules['platform_id']   = 'required|exists:platforms,id';      
            $rules['commission_admin_pct'] = 'nullable|numeric|min:0';
        } else { // 'purchase'
            $rules['buy_rate']      = 'required|numeric|min:0.00000001'; 
            $rules['received_rate'] = 'required|numeric|min:0.00000001'; 
            $rules['platform_id']   = 'nullable|exists:platforms,id';     
        }
        
        // 3. Validar los datos de entrada
        $validatedData = $request->validate($rules);
        
        // 🚨 4. CORRECCIÓN: Preparar el Array Final para el servicio
        
        $dataToService = $validatedData;
        
        // 4.1. Asegurar que las tasas no validadas condicionalmente existan (fix de error anterior)
        $dataToService['buy_rate'] = $request->get('buy_rate', null);
        $dataToService['received_rate'] = $request->get('received_rate', null);
        $dataToService['platform_id'] = $request->get('platform_id', null);
        $dataToService['commission_admin_pct'] = $request->get('commission_admin_pct', 0);
        
        // 4.2. **SOLUCIÓN A ERROR 1048**: Si es una Compra, usamos la Tasa de Compra para llenar la columna 'exchange_rate'
        if ($request->operation_type === 'purchase') {
            // El campo exchange_rate es NOT NULL en DB, se usa buy_rate para cumplir la restricción.
            $dataToService['exchange_rate'] = $dataToService['buy_rate']; 
        } else {
            $dataToService['exchange_rate'] = $request->get('exchange_rate', $dataToService['exchange_rate'] ?? null);
        }
        
        // 4.3. **SOLUCIÓN AL PROBLEMA DE MONTO CERO**: Añadir los montos calculados por el frontend.
        // Mapeamos 'charged' (frontend) a 'total' (DB/Service)
        $dataToService['commission_total_amount'] = $request->get('commission_charged_amount', 0);
        $dataToService['commission_provider_amount'] = $request->get('commission_provider_amount', 0);
        $dataToService['commission_admin_amount'] = $request->get('commission_admin_amount', 0);
        
        // NOTA: El campo commission_net_profit no se suele guardar en DB si es calculable, 
        // pero sí lo necesita el servicio si hace lógica contable basada en él.

        try {
            // Delega al servicio la lógica
            $transaction = $this->transactionService->createCurrencyExchange($dataToService);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            // Devolvemos el error de SQL de manera controlada para debugging.
            return response()->json(['message' => 'Error al procesar la transacción', 'error' => $e->getMessage()], 400); 
        }
    }

    public function show($id)
    {
        // 1. Buscar la transacción "físicamente" (ignorando filtros automáticos)
        $tx = CurrencyExchange::withoutGlobalScopes()->find($id);

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        // 2. SEGURIDAD MANUAL: Verificar que pertenece al Tenant del usuario actual
        // Como quitamos el Scope automático, debemos protegerlo aquí.
        $user = auth()->user();
        if ($tx->tenant_id != $user->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 3. CARGA MANUAL DE RELACIONES (Estilo "Hard Debug")
        // Buscamos cada pieza por su ID directamente.
        
        // Cliente
        $client = \App\Models\Client::withoutGlobalScopes()->find($tx->client_id);
        
        // Broker y su Usuario
        $broker = \App\Models\Broker::withoutGlobalScopes()->find($tx->broker_id);
        $brokerUser = $broker ? \App\Models\User::withoutGlobalScopes()->find($broker->user_id) : null;
        if ($broker && $brokerUser) {
            $broker->setRelation('user', $brokerUser); // Unir manual
        }

        // Proveedor
        $provider = \App\Models\Provider::withoutGlobalScopes()->find($tx->provider_id);
        
        // Admin (Usuario que registró)
        $admin = \App\Models\User::withoutGlobalScopes()->find($tx->admin_user_id);
        
        // Cuentas
        $fromAccount = \App\Models\Account::withoutGlobalScopes()->find($tx->from_account_id);
        $toAccount = \App\Models\Account::withoutGlobalScopes()->find($tx->to_account_id);

        // 4. INYECTAR LOS DATOS EN EL OBJETO PRINCIPAL
        // Usamos setRelation para que Laravel lo serialice en el JSON final
        $tx->setRelation('client', $client);
        $tx->setRelation('broker', $broker);
        $tx->setRelation('provider', $provider);
        $tx->setRelation('adminUser', $admin);
        $tx->setRelation('fromAccount', $fromAccount);
        $tx->setRelation('toAccount', $toAccount);

        return response()->json($tx);
    }
}