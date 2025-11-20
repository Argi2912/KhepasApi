<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\InternalTransaction;
use App\Models\LedgerEntry;
use App\Models\TransactionRequest;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage_transaction_requests');
    }

    public function index(Request $request)
    {
        $query = TransactionRequest::query()->with('client:id,name');

        // Filtro por estado (Pending por defecto es útil para el dashboard)
        $query->when($request->status, fn($q, $s) => $q->where('status', $s));
        $query->when($request->client_id, fn($q, $id) => $q->where('client_id', $id));

        return $query->latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'type'      => 'required|in:exchange,withdrawal',
            'amount'    => 'required|numeric|min:0',
            'currency_code' => 'required|string|size:3',
            'source_origin' => 'nullable|string', // "Banesco"
            'destination_target' => 'nullable|string', // "USDT"
            'notes'     => 'nullable|string',
        ]);

        $validated['status'] = TransactionRequest::STATUS_PENDING; // Siempre nace pendiente

        $req = TransactionRequest::create($validated);

        return response()->json($req, 201);
    }

    public function update(Request $request, TransactionRequest $transactionRequest)
    {
        // Básicamente para cambiar el estado a 'processed' o 'rejected'
        $validated = $request->validate([
            'status' => 'required|in:pending,processed,rejected',
            'notes'  => 'nullable|string'
        ]);

        $transactionRequest->update($validated);

        return response()->json($transactionRequest);
    }

    /**
     * Paga una deuda (LedgerEntry) generando un movimiento bancario real (InternalTransaction).
     */
    public function payDebt(LedgerEntry $ledgerEntry, $accountId, $userId)
    {
        return DB::transaction(function () use ($ledgerEntry, $accountId, $userId) {
            
            // 1. Validaciones
            if ($ledgerEntry->status === 'paid') {
                throw new \Exception("Esta deuda ya está pagada.");
            }

            $account = Account::lockForUpdate()->findOrFail($accountId);

            // Si es una deuda (payable), necesitamos saldo. 
            // Si es un cobro (receivable), no validamos saldo porque va a entrar dinero.
            if ($ledgerEntry->type === 'payable' && $account->balance < $ledgerEntry->amount) {
                throw new \Exception("Saldo insuficiente en la cuenta {$account->name} para pagar esta deuda.");
            }

            // 2. Crear el Movimiento de Caja (Internal Transaction)
            $internalTx = InternalTransaction::create([
                'tenant_id' => $ledgerEntry->tenant_id,
                'user_id' => $userId,
                'account_id' => $accountId,
                'type' => $ledgerEntry->type === 'payable' ? 'expense' : 'income', // Si pago deuda sale plata, si cobro entra.
                'category' => $ledgerEntry->type === 'payable' ? 'Pago de Comisiones' : 'Cobro de Comisiones',
                'amount' => $ledgerEntry->amount,
                'description' => "Pago/Cobro de Asiento #{$ledgerEntry->id}: {$ledgerEntry->description}",
                'transaction_date' => now(),
            ]);

            // 3. Actualizar el saldo de la cuenta
            if ($ledgerEntry->type === 'payable') {
                $account->decrement('balance', $ledgerEntry->amount);
            } else {
                $account->increment('balance', $ledgerEntry->amount);
            }

            // 4. Marcar la deuda como pagada
            $ledgerEntry->update([
                'status' => 'paid',
                // Opcional: Podrías agregar un campo 'payment_transaction_id' a ledger_entries en una migración futura
                // para saber exactamente con qué transacción se pagó.
            ]);

            return $internalTx;
        });
    }

    public function approve(Request $request, TransactionRequest $transactionRequest, TransactionService $txService)
    {
        if ($transactionRequest->status !== 'pending') {
            return response()->json(['message' => 'La solicitud no está pendiente'], 400);
        }

        $request->validate([
            'account_id' => 'required|exists:accounts,id' // Desde donde le pagamos al cliente
        ]);

        // Iniciamos transacción DB
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $transactionRequest, $txService) {
            
            // 1. Si es RETIRO, creamos el egreso de caja
            if ($transactionRequest->type === 'withdrawal') {
                $txService->createInternalTransaction([
                    'tenant_id' => $transactionRequest->tenant_id,
                    'account_id' => $request->account_id,
                    'user_id' => auth()->id(),
                    'type' => 'expense',
                    'category' => 'Retiro de Cliente',
                    'amount' => $transactionRequest->amount,
                    'description' => "Aprobación de solicitud #{$transactionRequest->id} - {$transactionRequest->notes}",
                    'transaction_date' => now(),
                ]);
            }

            // 2. Si es EXCHANGE, aquí deberías llamar a createCurrencyExchange
            // (Esto es más complejo porque requiere tasas, etc., quizás solo lo marcas y rediriges).

            // 3. Actualizamos la solicitud a procesada
            $transactionRequest->update([
                'status' => TransactionRequest::STATUS_PROCESSED
            ]);

            return response()->json(['message' => 'Solicitud aprobada y procesada']);
        });
    }
}