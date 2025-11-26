<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalTransaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;

class InternalTransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'account_id' => 'nullable|exists:accounts,id',
            'type'       => 'nullable|in:income,expense',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $query = InternalTransaction::query()
            ->with(['user:id,name', 'account:id,name,currency_code']);

        $query->when($request->account_id, fn($q, $id) => $q->where('account_id', $id));
        $query->when($request->type, fn($q, $type) => $q->where('type', $type));
        $query->when($request->start_date, fn($q, $d) => $q->whereDate('transaction_date', '>=', $d));
        $query->when($request->end_date, fn($q, $d) => $q->whereDate('transaction_date', '<=', $d));

        return $query->latest('transaction_date')->paginate(15);
    }

   public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id'   => 'required|exists:accounts,id',
            'user_id'      => 'required|exists:users,id',
            'type'         => 'required|in:income,expense',
            'category'     => 'required|string|max:100',
            'amount'       => 'required|numeric|min:0.01',
            'description'  => 'nullable|string|max:500',
            'transaction_date' => 'nullable|date',

            // --- AGREGA ESTAS LÍNEAS ---
            'dueño'       => 'nullable|string|max:255', // O 'required' si es obligatorio
            'person_name' => 'nullable|string|max:255', // O 'required' si es obligatorio
        ]);

        try {
            // Ahora $validated ya incluye 'dueño' y 'person_name'
            $transaction = $this->transactionService->createInternalTransaction($validated);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    // 🚨 NUEVO MÉTODO SHOW BLINDADO
    public function show($id)
    {
        // 1. Buscar sin scopes globales para encontrarlo sí o sí
        $tx = InternalTransaction::withoutGlobalScopes()->find($id);

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        // 2. Seguridad Manual (Tenant)
        if ($tx->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 3. Cargar relaciones manualmente para evitar fallos por soft deletes o scopes
        $user = \App\Models\User::withoutGlobalScopes()->find($tx->user_id);
        $account = \App\Models\Account::withoutGlobalScopes()->find($tx->account_id);

        // 4. Inyectar relaciones
        $tx->setRelation('user', $user);
        $tx->setRelation('account', $account);

        return response()->json($tx);
    }
}