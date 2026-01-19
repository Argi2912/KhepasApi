<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CurrencyExchange;
use App\Models\LedgerEntry;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class LedgerEntryController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        $query = LedgerEntry::query()
            ->with(['entity', 'transaction']);

        $query->when($request->type, fn($q, $t) => $q->where('type', $t));
        $query->when($request->status, fn($q, $s) => $q->where('status', $s));

        $query->when($request->search, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                
                    // [CORRECCIÓN PRINCIPAL AQUÍ]
                    // Usamos whereHasMorph para aplicar lógica distinta según el modelo
                    ->orWhereHasMorph('transaction', [
                        \App\Models\CurrencyExchange::class,
                        \App\Models\InternalTransaction::class
                    ], function ($q, $type) use ($search) {
                        
                        // Si es CurrencyExchange, buscamos por 'number' (CE-XXXX)
                        if ($type === \App\Models\CurrencyExchange::class) {
                            $q->where('number', 'like', "%{$search}%");
                        } 
                        // Si es InternalTransaction, buscamos por ID o Descripción (NO tiene number)
                        elseif ($type === \App\Models\InternalTransaction::class) {
                            $q->where('id', 'like', "%{$search}%")
                              ->orWhere('description', 'like', "%{$search}%");
                        }
                    })

                    ->orWhereHasMorph('entity', [
                        \App\Models\Employee::class,
                        \App\Models\Broker::class,
                        \App\Models\Client::class,
                        \App\Models\Investor::class,
                        \App\Models\Provider::class,
                        \App\Models\User::class,
                    ], function ($q, $type) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                        if ($type === \App\Models\Investor::class) {
                            $q->orWhere('alias', 'like', "%{$search}%");
                        }
                    });
            });
        });

        $query->when($request->start_date, fn($q, $date) => $q->whereDate('created_at', '>=', $date));
        $query->when($request->end_date, fn($q, $date) => $q->whereDate('created_at', '<=', $date));

        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description'   => 'required|string|max:500',
            'amount'        => 'required|numeric|min:0.01',
            'currency_code' => 'required|string|exists:currencies,code',
            'type'          => 'required|in:payable,receivable',
            'entity_type'   => 'required|string',
            'entity_id'     => 'required|integer|exists:' . class_basename($request->entity_type) . 's,id',
            'due_date'      => 'nullable|date',
        ]);

        $entry = LedgerEntry::create([
            'tenant_id'       => auth()->user()->tenant_id,
            'description'     => $validated['description'],
            'amount'          => $validated['amount'],
            'original_amount' => $validated['amount'],
            'currency_code'   => $validated['currency_code'],
            'type'            => $validated['type'],
            'entity_type'     => $validated['entity_type'],
            'entity_id'       => $validated['entity_id'],
            'due_date'        => $validated['due_date'] ?? null,
        ]);

        return response()->json($entry->load('entity'), 201);
    }

    public function summary()
    {
        // Total Por Pagar (Original)
        $payable = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->sum('amount');

        // Total Por Cobrar (Original)
        $receivableLedger = LedgerEntry::where('type', 'receivable')
            ->where('status', 'pending')
            ->sum('amount');

        // Sumar Compras Pendientes
        $receivablePurchases = CurrencyExchange::where('status', 'pending')
            ->whereNotNull('buy_rate')
            ->where('buy_rate', '>', 0)
            ->sum('amount_received');

        // Top 5 Acreedores
        $topPayables = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->select('entity_type', 'entity_id', DB::raw('SUM(amount) as total'))
            ->groupBy('entity_type', 'entity_id')
            ->with('entity')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'payable_total'    => $payable,
            'receivable_total' => $receivableLedger + $receivablePurchases,
            'top_debts'        => $topPayables,
        ]);
    }

    public function pay(Request $request, LedgerEntry $ledgerEntry)
    {
        $request->validate([
            'account_id'  => 'required|exists:accounts,id',
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
        ]);

        // Validar compatibilidad de Divisas
        $account = Account::findOrFail($request->account_id);

        if ($account->currency_code !== $ledgerEntry->currency_code) {
            return response()->json([
                'message' => "Error de Divisa: La deuda es en {$ledgerEntry->currency_code} pero intentas pagar con una cuenta en {$account->currency_code}."
            ], 422);
        }

        $paymentAmount = $request->amount;
        $pending       = $ledgerEntry->original_amount - $ledgerEntry->paid_amount;

        if ($paymentAmount > $pending) {
            return response()->json(['message' => "El monto excede el saldo pendiente ({$pending})"], 400);
        }

        try {
            $tx = $this->transactionService->processLedgerPayment($ledgerEntry, $request->account_id, $paymentAmount, $request->description);
            return response()->json([
                'message'      => 'Abono registrado correctamente',
                'payment'      => $tx,
                'ledger_entry' => $ledgerEntry->fresh(['payments', 'entity']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}