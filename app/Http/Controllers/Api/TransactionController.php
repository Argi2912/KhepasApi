<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountPayableRequest;
use App\Http\Requests\StoreAccountReceivableRequest;
use App\Http\Requests\StoreDirectIngressRequest;
use App\Http\Requests\StoreDirectEgressRequest;
use App\Http\Requests\PayAccountPayableRequest; // Nuevo
use App\Http\Requests\ReceiveAccountReceivableRequest; // Nuevo
use App\Models\Transaction;
use App\Services\Interfaces\AccountingServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    protected AccountingServiceInterface $accountingService;

    // DIP: Inyección del servicio contable via Interfaz
    public function __construct(AccountingServiceInterface $accountingService)
    {
        $this->accountingService = $accountingService;
        // Middleware para asegurar que solo usuarios con el rol 'Tenant Admin' o 'Cashier' 
        // puedan ejecutar estas acciones (usando Laravel Permission)
        $this->middleware('permission:register cxp|register cxc|register direct ingress|register direct egress', 
                          ['only' => ['storeAccountPayable', 'storeAccountReceivable', 'storeDirectIngress', 'storeDirectEgress']]);
    }

    public function index(Request $request): JsonResponse
    {
       $query = Transaction::query()
                            ->with(['user', 'details.account'])
                            ->latest('date');

        // --- 1. BÚSQUEDA GENERAL (SEARCH) ---
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('description', 'like', $searchTerm)
                  ->orWhere('reference_code', 'like', $searchTerm);
            });
        }

        // --- 2. FILTROS ESPECÍFICOS ---
        
        // Filtro por ESTADO
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filtro por USUARIO (Corredor, Cliente, etc. que inició la transacción)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filtro por RANGO DE FECHAS
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // --- 3. PAGINACIÓN ---
        $perPage = $request->get('per_page', 20);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Muestra el detalle de una transacción específica.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        // El Global Scope asegura que solo se acceda a transacciones del tenant
        return response()->json($transaction->load(['user', 'details.account', 'relatedAccounts']));
    }

    /**
     * Registra una Cuenta por Pagar (CXP) - Documento: "Si estoy registrando una cuenta por pagar"
     * Lógica: CXP (+) POSITIVO -> Caja/Banco (-) NEGATIVO
     */
    public function storeAccountPayable(StoreAccountPayableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        // **Lógica del Documento:**
        // 1. Aumento de Pasivo (Cuenta por Pagar) -> CRÉDITO
        // 2. Aumento de Egreso (Gasto) -> DÉBITO
        
        $movements = [
            [ 
                'account_name' => 'Cuentas por Pagar (Maestro)', 
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (CXP aumenta)
                'account_type' => 'CXP'
            ],
            [ 
                'account_name' => 'Egresos por Operaciones (Directo)', 
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (Gasto aumenta)
                'account_type' => 'EGRESS'
            ]
        ];
        
        try {
            $transaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Registro de CXP: ' . $validated['description'],
                    'status' => 'PENDING', // CXP es pendiente por naturaleza
                ],
                $movements
            );

            return response()->json(['message' => 'CXP registrada exitosamente.', 'transaction' => $transaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar CXP.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra una Cuenta por Cobrar (CXC) - Documento: "Si estoy registrando una cuenta por cobrar"
     * Lógica: CXC (+) POSITIVO -> Ingreso (-) NEGATIVO
     */
    public function storeAccountReceivable(StoreAccountReceivableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        // **Lógica del Documento:**
        // 1. Aumento de Activo (Cuenta por Cobrar) -> DÉBITO
        // 2. Aumento de Ingreso -> CRÉDITO
        
        $movements = [
            [ 
                'account_name' => 'Cuentas por Cobrar (Maestro)', 
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (CXC aumenta)
                'account_type' => 'CXC'
            ],
            [ 
                'account_name' => 'Ingresos por Operaciones (Directo)', 
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (Ingreso aumenta)
                'account_type' => 'INGRESS'
            ]
        ];
        
        try {
            $transaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Registro de CXC: ' . $validated['description'],
                    'status' => 'PENDING', // CXC es pendiente por naturaleza
                ],
                $movements
            );

            return response()->json(['message' => 'CXC registrada exitosamente.', 'transaction' => $transaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar CXC.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra un Ingreso Directo - Documento: "Estoy registrando un ingreso"
     * Lógica: Caja (+) POSITIVO -> Ingreso (+) POSITIVO
     */
    public function storeDirectIngress(StoreDirectIngressRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        
        // Asumiendo que el 'cash_account_name' viene del Request (Ej: 'Caja Principal (Efectivo)')
        
        $movements = [
            [ 
                'account_name' => $validated['cash_account_name'], 
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (Activo/Caja aumenta)
                'account_type' => 'CASH'
            ],
            [ 
                'account_name' => 'Ingresos por Operaciones (Directo)', 
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (Ingreso aumenta)
                'account_type' => 'INGRESS'
            ]
        ];

        try {
            $transaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Ingreso directo: ' . $validated['description'],
                    'status' => 'COMPLETED',
                ],
                $movements
            );
            
            return response()->json(['message' => 'Ingreso registrado exitosamente.', 'transaction' => $transaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar ingreso.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra un Egreso Directo - Documento: "Estoy registrando un egreso"
     * Lógica: Caja (-) NEGATIVO -> Egreso (+) POSITIVO
     */
    public function storeDirectEgress(StoreDirectEgressRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        
        $movements = [
            [ 
                'account_name' => 'Egresos por Operaciones (Directo)', 
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (Gasto/Egreso aumenta)
                'account_type' => 'EGRESS'
            ],
            [ 
                'account_name' => $validated['cash_account_name'], 
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (Activo/Caja disminuye)
                'account_type' => 'CASH'
            ]
        ];

        try {
            $transaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Egreso directo: ' . $validated['description'],
                    'status' => 'COMPLETED',
                ],
                $movements
            );
            
            return response()->json(['message' => 'Egreso registrado exitosamente.', 'transaction' => $transaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar egreso.', 'error' => $e->getMessage()], 500);
        }
    }

    public function payAccountPayable(PayAccountPayableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        
        // La transacción original de la CXP
        $originalCXP = Transaction::findOrFail($validated['cxp_transaction_id']);

        // **Lógica Contable:**
        // 1. Disminución de Pasivo (CXP) -> DÉBITO
        // 2. Disminución de Activo (Caja/Banco) -> CRÉDITO
        
        $movements = [
            [ 
                'account_name' => 'Cuentas por Pagar (Maestro)', 
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (CXP disminuye)
                'account_type' => 'CXP'
            ],
            [ 
                'account_name' => $validated['cash_account_name'], // Cuenta de donde sale el dinero
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (Caja/Activo disminuye)
                'account_type' => 'CASH'
            ]
        ];

        try {
            $paymentTransaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Pago de CXP #' . $originalCXP->reference_code,
                    'status' => 'COMPLETED',
                ],
                $movements,
                $originalCXP->id // ID de la transacción original de CXP
            );

            // Marcar la CXP original como COMPLETADA si se paga en su totalidad
            // Para lógica de pagos parciales, esto debería ser más complejo, pero para el MVP, asumimos un pago completo o se deja en PENDING.
            $originalCXP->update(['status' => 'COMPLETED']);

            return response()->json(['message' => 'Pago de deuda CXP registrado exitosamente.', 'transaction' => $paymentTransaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al pagar deuda CXP.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Recibe un pago de CXC pendiente. Documento: "Si estoy recibiendo pago de una cuenta por cobrar"
     * Lógica: Caja (+) POSITIVO -> CXC (-) NEGATIVO
     */
    public function receiveAccountReceivable(ReceiveAccountReceivableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        
        // La transacción original de la CXC
        $originalCXC = Transaction::findOrFail($validated['cxc_transaction_id']);

        // **Lógica Contable:**
        // 1. Aumento de Activo (Caja/Banco) -> DÉBITO
        // 2. Disminución de Activo (CXC) -> CRÉDITO
        
        $movements = [
            [ 
                'account_name' => $validated['cash_account_name'], // Cuenta a donde entra el dinero
                'amount' => $validated['amount'], 
                'is_debit' => true, // DÉBITO (Caja/Activo aumenta)
                'account_type' => 'CASH'
            ],
            [ 
                'account_name' => 'Cuentas por Cobrar (Maestro)', 
                'amount' => $validated['amount'], 
                'is_debit' => false, // CRÉDITO (CXC/Activo disminuye)
                'account_type' => 'CXC'
            ]
        ];

        try {
            $paymentTransaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Cobro de CXC #' . $originalCXC->reference_code,
                    'status' => 'COMPLETED',
                ],
                $movements,
                $originalCXC->id // ID de la transacción original de CXC
            );

            // Marcar la CXC original como COMPLETADA
            $originalCXC->update(['status' => 'COMPLETED']);

            return response()->json(['message' => 'Cobro de CXC registrado exitosamente.', 'transaction' => $paymentTransaction], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al cobrar CXC.', 'error' => $e->getMessage()], 500);
        }
    }
}