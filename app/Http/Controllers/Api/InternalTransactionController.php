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

        // ✅ CAMBIO 1: Agregamos 'entity' aquí. 
        // Esto hace que Laravel le envíe al Frontend quién es la persona (Cliente, Empleado, etc.)
        $query = InternalTransaction::query()
            ->with(['user:id,name', 'account:id,name,currency_code', 'entity']); 

        $query->when($request->account_id, fn($q, $id) => $q->where('account_id', $id));
        $query->when($request->type, fn($q, $type) => $q->where('type', $type));
        $query->when($request->start_date, fn($q, $d) => $q->whereDate('transaction_date', '>=', $d));
        $query->when($request->end_date, fn($q, $d) => $q->whereDate('transaction_date', '<=', $d));

        return $query->latest('transaction_date')->paginate(15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id'       => 'required|exists:accounts,id',
            'user_id'          => 'required|exists:users,id',
            'type'             => 'required|in:income,expense',
            'category'         => 'required|string|max:100',
            'amount'           => 'required|numeric|min:0.01',
            'description'      => 'nullable|string|max:500',
            'transaction_date' => 'nullable|date',
            'dueño'            => 'nullable|string|max:255',
            'person_name'      => 'nullable|string|max:255',

            // ✅ CAMBIO 2: Agregamos estos dos campos.
            // Sin esto, Laravel ignora la relación y no guarda quién es el cliente en la base de datos.
            'entity_type'      => 'nullable|string|max:255',
            'entity_id'        => 'nullable|integer',
        ]);

        try {
            $transaction = $this->transactionService->createInternalTransaction($validated);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    // 🚨 MÉTODO SHOW BLINDADO (Se mantiene igual, solo agregamos una línea)
    public function show($id)
    {
        $tx = InternalTransaction::withoutGlobalScopes()->find($id);

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        if ($tx->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $user = \App\Models\User::withoutGlobalScopes()->find($tx->user_id);
        $account = \App\Models\Account::withoutGlobalScopes()->find($tx->account_id);

        // ✅ CAMBIO 3: Cargamos la entidad también en el detalle individual
        $tx->load('entity');

        $tx->setRelation('user', $user);
        $tx->setRelation('account', $account);

        return response()->json($tx);
    }
}