<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashRequest;
use App\Http\Requests\UpdateCashRequest;
use App\Models\Cash;
use App\Models\CashClosure;
use App\Models\TransactionDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CashController extends Controller
{
    public function __construct()
    {
        // Solo usuarios con permiso para gestionar cajas pueden acceder
        $this->middleware('permission:manage cashes');
    }

    /**
     * Muestra una lista de todas las plataformas de caja.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Cash::with(['account', 'currency'])->latest();

        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where('name', 'like', $searchTerm);
        }

        $perPage = $request->get('per_page', 20);
        $cashes  = $query->paginate($perPage);

        return response()->json($cashes);
    }

    /**
     * Almacena una nueva plataforma de caja.
     */
    public function store(StoreCashRequest $request): JsonResponse
    {
        // 1. Los datos vienen validados y autorizados por StoreCashRequest
        $validatedData = $request->validated();

        // 2. Obtener el tenant_id del usuario autenticado
        $tenantId = Auth::user()->tenant_id;

        // 3. --- SOLUCIÓN: ASIGNACIÓN MANUAL CON ->SAVE() ---
        $cash = new Cash();

                                       // Asignación de campos obligatorios
        $cash->tenant_id  = $tenantId; // Evita que el Trait falle
        $cash->account_id = $validatedData['account_id'];
        $cash->name       = $validatedData['name'];

        $cash->currency_id = $validatedData['currency_id'];

        // Asignación del balance
        $cash->balance = $validatedData['balance'];

        // 4. Guardar forzado
        $cash->save();

        // 5. Devolver la caja cargada con la relación (para la tabla del frontend)
        return response()->json([
            'message' => 'Caja creada exitosamente.',
            'cash'    => $cash->load('account', 'currency'),
        ], 201);

    }

    /**
     * Muestra el detalle de una plataforma de caja.
     */
    public function show(Cash $cash): JsonResponse
    {
        // El Global Scope garantiza que solo se muestre la caja del tenant actual
        return response()->json($cash->load('account'));
    }

    /**
     * Actualiza la plataforma de caja.
     */
    public function update(UpdateCashRequest $request, Cash $cash): JsonResponse
    {
        // En el update, no queremos actualizar el balance, solo name y account_id
        $data = $request->validated();

        // Eliminamos balance de los datos validados antes de update
        unset($data['balance']);

        // El update debería funcionar bien, ya que el objeto ya existe
        $cash->update($data);

        return response()->json(['message' => 'Caja actualizada exitosamente.', 'cash' => $cash->load('account', 'currency')]);
    }

    /**
     * Elimina una plataforma de caja.
     */
    public function destroy(Cash $cash): JsonResponse
    {
        // 1. Validación contable: No eliminar si tiene saldo.
        // Si el balance no se ha cargado correctamente de la BDD, esto podría fallar.
        // Asegurémonos de que el balance sea numérico para la comparación.
        if ((float) $cash->balance > 0.00) {
            return response()->json([
                'message' => 'No se puede eliminar una caja con saldo. Realice una transacción de egreso (cierre) primero.',
            ], 422); // 422 Unprocessable Content
        }

        // 2. Eliminación
        $cash->delete();
        return response()->json(['message' => 'Caja eliminada exitosamente.']);
    }

    public function startClosure(Request $request): JsonResponse
    {
        $this->validate($request, [
            'cash_id'         => 'required|exists:cashes,id',
            'initial_balance' => 'required|numeric|min:0',
        ]);

        $cashId = $request->cash_id;
        $user   = Auth::user();

        // 1. Verificar si ya existe una caja abierta
        $openClosure = CashClosure::where('cash_id', $cashId)
            ->whereNull('end_date')
            ->first();

        if ($openClosure) {
            return response()->json(['message' => 'Esta caja ya tiene un cierre abierto. Debe cerrarla primero.'], 409);
        }

        // 2. Crear el registro de apertura
        $closure = CashClosure::create([
            'tenant_id'       => $user->tenant_id,
            'cash_id'         => $cashId,
            'user_id'         => $user->id,
            'start_date'      => now(),
            'initial_balance' => $request->initial_balance,
        ]);

        return response()->json(['message' => 'Cierre de caja iniciado (apertura registrada).', 'closure' => $closure], 201);
    }

    /**
     * Finaliza el proceso de Cierre de Caja (Cuadre).
     */
    public function endClosure(Request $request): JsonResponse
    {
        $this->validate($request, [
            'cash_id'       => 'required|exists:cashes,id',
            'final_balance' => 'required|numeric',
        ]);

        $cashId = $request->cash_id;

        // 1. Obtener la caja abierta
        $closure = CashClosure::where('cash_id', $cashId)
            ->whereNull('end_date')
            ->firstOrFail();

        // 2. Calcular el Saldo Teórico (Transacciones Netas)
        // Se calcula el saldo neto de la cuenta de caja asociada desde la apertura
        $cashAccount = Cash::findOrFail($cashId)->account;

        $netMovement = TransactionDetail::where('account_id', $cashAccount->id)
            ->whereHas('transaction', function ($q) use ($closure) {
                $q->where('date', '>=', $closure->start_date);
            })
            ->sum(DB::raw('CASE WHEN is_debit = 1 THEN amount ELSE -amount END'));

        // Saldo teórico = Balance Inicial + Movimiento Neto
        $theoreticalBalance = $closure->initial_balance + $netMovement;
        $difference         = $request->final_balance - $theoreticalBalance;

        // 3. Actualizar el registro de cierre
        $closure->update([
            'end_date'      => now(),
            'final_balance' => $request->final_balance,
            'difference'    => $difference,
        ]);

        return response()->json([
            'message'             => 'Cierre de caja completado.',
            'closure'             => $closure,
            'theoretical_balance' => round((float) $theoreticalBalance, 2),
            'difference'          => round((float) $difference, 2),
        ]);
    }
}
