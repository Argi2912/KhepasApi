<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurrencyExchangeRequest; // 👈 Importamos el FormRequest creado
use App\Models\CurrencyExchange;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CurrencyExchangeController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        // Validación ligera para filtros de búsqueda
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
                'toAccount:id,name,currency_code',
                'investor:id,name,alias',
            ]);

        $query->when($request->client_id, fn($q, $id) => $q->clientId($id));
        $query->when($request->broker_id, fn($q, $id) => $q->brokerId($id));
        $query->when($request->admin_user_id, fn($q, $id) => $q->adminUserId($id));
        $query->when($request->status, fn($q, $status) => $q->where('status', $status));
        $query->when($request->start_date, fn($q, $date) => $q->fromDate($date));
        $query->when($request->end_date, fn($q, $date) => $q->toDate($date));

        return $query->latest()->paginate(15)->withQueryString();
    }

    /**
     * Store utiliza ahora StoreCurrencyExchangeRequest para validación estricta.
     * La lógica de cálculo de dinero se ha movido al TransactionService.
     */
    public function store(StoreCurrencyExchangeRequest $request)
    {
        // 1. Obtener datos ya saneados y validados
        $data = $request->validated();

        // 2. Normalización de Datos
        // Determinamos el estado inicial basado en flags booleanos
        $isDelivered = $request->boolean('delivered', true);
        $isPaid      = $request->boolean('paid', true);

        $status = 'completed';
        
        // Lógica de estados para Compras (Purchase)
        if ($data['operation_type'] === 'purchase') {
            if (!$isDelivered && !$isPaid) {
                $status = 'pending_both';     // Ni entregado ni pagado
            } elseif (!$isDelivered) {
                $status = 'pending_delivery'; // Falta entregar divisa
            } elseif (!$isPaid) {
                $status = 'pending_payment';  // Falta pagar bolívares
            }
            
            // En compras, aseguramos que exchange_rate sea consistente
            // (Si viene received_rate, lo usamos como la tasa oficial del registro)
            $data['exchange_rate'] = $data['received_rate'] ?? $data['buy_rate'];
        }

        // Agregamos datos de control al array que irá al servicio
        $data['status']    = $status;
        $data['delivered'] = $isDelivered;
        $data['paid']      = $isPaid;
        $data['type']      = $data['operation_type']; // Normalizar nombre para la BD

        try {
            // 3. Delegar al servicio (Backend Authority)
            // El servicio ignorará cualquier monto calculado y usará los porcentajes
            $transaction = $this->transactionService->createCurrencyExchange($data);
            
            return response()->json($transaction, 201);

        } catch (\Exception $e) {
            // Loguear el error real internamente
            Log::error("Error creando intercambio: " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            // Responder al usuario
            return response()->json([
                'message' => 'Error al procesar la transacción', 
                'error' => $e->getMessage() // En prod podrías ocultar esto
            ], 400);
        }
    }

    public function markDelivered(CurrencyExchange $exchange)
    {
        // 1. Validación de lógica de negocio
        if ($exchange->type !== 'purchase') {
            return response()->json(['message' => 'Solo aplicable a compras de divisa (cash)'], 400);
        }

        if ($exchange->status === 'completed') {
            return response()->json(['message' => 'Ya está marcada como entregada'], 400);
        }

        // 2. Seguridad (Permissions)
        $user = Auth::user();
        if (! $user->can('manage_exchanges') && ! $user->hasRole('superadmin')) {
            return response()->json(['message' => 'No tienes permiso para confirmar entregas.'], 403);
        }

        // 3. Actualización
        $exchange->status = 'completed';
        $exchange->save();

        // Opcional: Aquí podrías disparar un evento si necesitas recalcular algo más en el futuro

        return response()->json(['message' => 'Transacción marcada como entregada']);
    }

    public function show($id)
    {
        // Usamos withoutGlobalScopes para buscar, luego validamos el tenant manualmente 
        // para evitar errores 404 confusos si el ID existe en otro tenant.
        $tx = CurrencyExchange::withoutGlobalScopes()->find($id);

        if (! $tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        // Validación de seguridad Multi-Tenant
        $user = auth()->user();
        if ($tx->tenant_id != $user->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tx->load([
            'client',
            'broker.user',
            'provider',
            'adminUser',
            'fromAccount',
            'toAccount',
            'investor',
        ]);

        return response()->json($tx);
    }
}