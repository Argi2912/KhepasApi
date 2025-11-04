<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountPayableRequest;
use App\Http\Requests\StoreAccountReceivableRequest;
use App\Http\Requests\StoreDirectIngressRequest;
use App\Http\Requests\StoreDirectEgressRequest;
use App\Http\Requests\PayAccountPayableRequest; 
use App\Http\Requests\ReceiveAccountReceivableRequest;
use App\Models\Transaction;
use App\Models\Cash; // Nuevo: Necesario para actualizar el balance
use App\Models\Account; // Nuevo: Necesario para buscar cuentas por nombre
use App\Models\TransactionDetail; // Nuevo: Necesario para crear detalles
use App\Services\Interfaces\AccountingServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // Nuevo: Necesario para atomicidad
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    protected AccountingServiceInterface $accountingService;

    // La inyección del servicio se mantiene para mantener la estructura, aunque la lógica de balance se mueve aquí.
    public function __construct(AccountingServiceInterface $accountingService)
    {
        $this->accountingService = $accountingService;
        $this->middleware('permission:register cxp|register cxc|register direct ingress|register direct egress|pay cxp debt|receive cxc payment');
    }

    /**
     * Helper para obtener el ID de una cuenta por su nombre.
     */
    private function getAccountId(string $accountName, int $tenantId): int
    {
        return Account::where('name', $accountName)
                      ->where('tenant_id', $tenantId)
                      ->firstOrFail()
                      ->id;
    }

    // ... (index y show se mantienen sin cambios) ...

    /**
     * Muestra la lista paginada de Transacciones.
     */
    public function index(Request $request): JsonResponse
    {
       $query = Transaction::query()->with(['user', 'details.account'])->latest();
       $perPage = $request->get('per_page', 20);
       return response()->json($query->paginate($perPage));
    }

    /**
     * Muestra el detalle de una transacción.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json(
        $transaction->load(['user', 'details.account', 'relatedAccounts'])
    );
    }

    // ==========================================================
    // SECCIÓN 1: REGISTRO CXP/CXC (PENDING - NO AFECTA BALANCE DE CAJA)
    // ==========================================================

    public function storeAccountPayable(StoreAccountPayableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        
        try {
            return DB::transaction(function () use ($validated, $user, $tenantId) {
                
                $cxpAccountId = $this->getAccountId('Cuentas por Pagar (Maestro)', $tenantId);
                $egressAccountId = $this->getAccountId('Egresos por Operaciones (Directo)', $tenantId);
                
                // 1. Crear el Encabezado de la Transacción
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Registro de CXP: ' . $validated['description'],
                    'status' => 'PENDING',
                    'reference_code' => 'CXP-' . Str::random(6), 
                ]);
                
                // 2. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $egressAccountId, // DÉBITO: Gasto (Aumenta el pasivo futuro)
                    'amount' => $validated['amount'],
                    'is_debit' => true,
                ]);
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cxpAccountId, // CRÉDITO: CXP (Pasivo aumenta)
                    'amount' => $validated['amount'],
                    'is_debit' => false,
                ]);

                return response()->json(['message' => 'CXP registrada exitosamente.', 'transaction' => $transaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar CXP.', 'error' => $e->getMessage()], 500);
        }
    }

    public function storeAccountReceivable(StoreAccountReceivableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        
        try {
            return DB::transaction(function () use ($validated, $user, $tenantId) {

                $cxcAccountId = $this->getAccountId('Cuentas por Cobrar (Maestro)', $tenantId);
                $ingressAccountId = $this->getAccountId('Ingresos por Operaciones (Directo)', $tenantId);
                
                // 1. Crear el Encabezado de la Transacción
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Registro de CXC: ' . $validated['description'],
                    'status' => 'PENDING', 
                    'reference_code' => 'CXC-' . Str::random(6), 
                ]);
                
                // 2. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cxcAccountId, // DÉBITO: CXC (Activo aumenta)
                    'amount' => $validated['amount'],
                    'is_debit' => true,
                ]);
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $ingressAccountId, // CRÉDITO: Ingresos (Patrimonio aumenta)
                    'amount' => $validated['amount'],
                    'is_debit' => false,
                ]);

                return response()->json(['message' => 'CXC registrada exitosamente.', 'transaction' => $transaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar CXC.', 'error' => $e->getMessage()], 500);
        }
    }

    // ==========================================================
    // SECCIÓN 2: REGISTRO DIRECTO (COMPLETED - MODIFICA BALANCE DE CAJA)
    // ==========================================================
    
    public function storeDirectIngress(StoreDirectIngressRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        $cashAccountName = $validated['cash_account_name'] ?? 'Cuenta de Caja Desconocida';
        
        try {
            return DB::transaction(function () use ($validated, $user, $tenantId, $cashAccountName) {
                
                // 1. Bloquear la Caja para Atomicidad
                $cash = Cash::where('id', $validated['cash_id'])
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->firstOrFail();
                
                $cashAccountId = $cash->account_id;
                $ingressAccountId = $this->getAccountId('Ingresos por Operaciones (Directo)', $tenantId);
                
                // 2. Crear Encabezado
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Ingreso directo: ' . $validated['description'],
                    'status' => 'COMPLETED',
                    'reference_code' => 'ING-' . Str::random(6),
                ]);
                
                // 3. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cashAccountId,
                    'amount' => $validated['amount'],
                    'is_debit' => true, // DÉBITO: Caja (Aumenta)
                ]);
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $ingressAccountId,
                    'amount' => $validated['amount'],
                    'is_debit' => false, // CRÉDITO: Ingresos (Aumenta)
                ]);

                // 4. ACTUALIZACIÓN CRUCIAL DEL BALANCE (FIX)
                $cash->balance += $validated['amount'];
                $cash->save(); 

                return response()->json(['message' => 'Ingreso registrado exitosamente.', 'transaction' => $transaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], $e->getCode() == 422 ? 422 : 500);
        }
    }

    public function storeDirectEgress(StoreDirectEgressRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        $cashAccountName = $validated['cash_account_name'] ?? 'Cuenta de Caja Desconocida';

        try {
            return DB::transaction(function () use ($validated, $user, $tenantId, $cashAccountName) {
                
                // 1. Bloquear la Caja y Validar Fondos
                $cash = Cash::where('id', $validated['cash_id'])
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->firstOrFail();
                
                if ($cash->balance < $validated['amount']) {
                    throw new \Exception('Fondos insuficientes en la caja para realizar este egreso.', 422);
                }
                
                $cashAccountId = $cash->account_id;
                $egressAccountId = $this->getAccountId('Egresos por Operaciones (Directo)', $tenantId);

                // 2. Crear Encabezado
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Egreso directo: ' . $validated['description'],
                    'status' => 'COMPLETED',
                    'reference_code' => 'EGR-' . Str::random(6),
                ]);

                // 3. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $egressAccountId, // DÉBITO: Egresos (Aumenta)
                    'amount' => $validated['amount'],
                    'is_debit' => true,
                ]);
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cashAccountId, // CRÉDITO: Caja (Disminuye)
                    'amount' => $validated['amount'],
                    'is_debit' => false,
                ]);

                // 4. ACTUALIZACIÓN CRUCIAL DEL BALANCE (FIX)
                $cash->balance -= $validated['amount'];
                $cash->save(); 

                return response()->json(['message' => 'Egreso registrado exitosamente.', 'transaction' => $transaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], $e->getCode() == 422 ? 422 : 500);
        }
    }
    
    // ==========================================================
    // SECCIÓN 3: SALDAR CUENTAS (PAGOS/COBROS - COMPLETED)
    // ==========================================================

    public function payAccountPayable(PayAccountPayableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        $originalCXP = Transaction::findOrFail($validated['cxp_transaction_id']);
        
        try {
            return DB::transaction(function () use ($validated, $user, $tenantId, $originalCXP) {

                // 1. Bloquear Caja y Validar Fondos
                $cash = Cash::where('id', $validated['cash_id'])
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->firstOrFail();
                
                if ($cash->balance < $validated['amount']) {
                    throw new \Exception('Fondos insuficientes en la caja para realizar este pago.', 422);
                }

                $cashAccountId = $cash->account_id;
                $cxpAccountId = $this->getAccountId('Cuentas por Pagar (Maestro)', $tenantId);

                // 2. Crear Encabezado de Pago
                $paymentTransaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Pago de CXP #' . $originalCXP->reference_code,
                    'status' => 'COMPLETED',
                    'reference_code' => 'PAG-' . Str::random(6),
                ]);

                // 3. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $paymentTransaction->id,
                    'account_id' => $cxpAccountId, // DÉBITO: CXP (Pasivo disminuye)
                    'amount' => $validated['amount'],
                    'is_debit' => true,
                ]);
                TransactionDetail::create([
                    'transaction_id' => $paymentTransaction->id,
                    'account_id' => $cashAccountId, // CRÉDITO: Caja (Activo disminuye)
                    'amount' => $validated['amount'],
                    'is_debit' => false,
                ]);

                // 4. ACTUALIZACIÓN CRUCIAL DEL BALANCE
                $cash->balance -= $validated['amount'];
                $cash->save(); 

                // 5. Marcar la CXP original como COMPLETADA
                $originalCXP->update(['status' => 'COMPLETED']);

                return response()->json(['message' => 'Pago de deuda CXP registrado exitosamente.', 'transaction' => $paymentTransaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], $e->getCode() == 422 ? 422 : 500);
        }
    }

    public function receiveAccountReceivable(ReceiveAccountReceivableRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        $originalCXC = Transaction::findOrFail($validated['cxc_transaction_id']);
        
        try {
            return DB::transaction(function () use ($validated, $user, $tenantId, $originalCXC) {

                // 1. Bloquear Caja
                $cash = Cash::where('id', $validated['cash_id'])
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->firstOrFail();
                
                $cashAccountId = $cash->account_id;
                $cxcAccountId = $this->getAccountId('Cuentas por Cobrar (Maestro)', $tenantId);
                
                // 2. Crear Encabezado de Cobro
                $paymentTransaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => 'Cobro de CXC #' . $originalCXC->reference_code,
                    'status' => 'COMPLETED',
                    'reference_code' => 'COB-' . Str::random(6),
                ]);

                // 3. Crear Detalles (Doble Entrada)
                TransactionDetail::create([
                    'transaction_id' => $paymentTransaction->id,
                    'account_id' => $cashAccountId, // DÉBITO: Caja (Activo aumenta)
                    'amount' => $validated['amount'],
                    'is_debit' => true,
                ]);
                TransactionDetail::create([
                    'transaction_id' => $paymentTransaction->id,
                    'account_id' => $cxcAccountId, // CRÉDITO: CXC (Activo disminuye)
                    'amount' => $validated['amount'],
                    'is_debit' => false,
                ]);

                // 4. ACTUALIZACIÓN CRUCIAL DEL BALANCE
                $cash->balance += $validated['amount'];
                $cash->save(); 

                // 5. Marcar la CXC original como COMPLETADA
                $originalCXC->update(['status' => 'COMPLETED']);

                return response()->json(['message' => 'Cobro de CXC registrado exitosamente.', 'transaction' => $paymentTransaction], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al cobrar CXC.', 'error' => $e->getMessage()], 500);
        }
    }
}