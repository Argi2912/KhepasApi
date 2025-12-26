<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalTransaction;
use App\Models\Account;
use App\Models\Investor;
use App\Models\Provider;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InternalTransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        $query = InternalTransaction::query()
            ->with(['user:id,name', 'account:id,name,currency_code', 'entity']);

        $query->when($request->account_id, fn($q, $id) => $q->where('account_id', $id));
        $query->when($request->type, fn($q, $type) => $q->where('type', $type));

        return $query->latest('transaction_date')->paginate(15);
    }

    public function store(Request $request)
    {
        // Validación (Se mantiene igual)
        $validated = $request->validate([
            'source_type'      => 'nullable|in:account,investor,provider',
            'account_id'       => 'required',
            'user_id'          => 'required',
            'type'             => 'required|in:income,expense',
            'amount'           => 'required|numeric|min:0.01',
            'category'         => 'required',
            'transaction_date' => 'nullable|date',
            'entity_type'      => 'nullable|string',
            'entity_id'        => 'nullable|integer',
            'description'      => 'nullable',
            'dueño'            => 'nullable',
            'person_name'      => 'nullable',
        ]);

        // Asignar valor por defecto
        $validated['source_type'] = $validated['source_type'] ?? 'account';

        try {
            // Llamamos al servicio. Si no hay saldo, el servicio lanzará Exception.
            $transaction = $this->transactionService->createInternalTransaction($validated);

            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            // Capturamos la excepción del servicio (ej. "Saldo insuficiente")
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show($id)
    {
        $tx = InternalTransaction::withoutGlobalScopes()->with('entity')->find($id);
        if (!$tx) return response()->json(['message' => 'No encontrado'], 404);
        return response()->json($tx);
    }
}
