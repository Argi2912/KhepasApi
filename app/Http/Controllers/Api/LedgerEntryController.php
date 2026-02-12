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

    /**
     * 1. VISTA DETALLADA (LISTA DE DEUDAS/COBROS)
     */
    public function index(Request $request)
    {
        // Cargamos 'currency' para asegurar que el Frontend reciba el símbolo correcto (Bs, $, €)
        $query = LedgerEntry::query()
            ->with(['entity', 'transaction', 'currency']);

        // --- FILTROS ---

        // 1. Tipo (Por Cobrar vs Por Pagar)
        if ($request->has('type')) {
            $query->where('type', $request->type);
            // ✅ CORRECCIÓN: YA NO FILTRAMOS transaction_type.
            // Ahora aparecerán las comisiones automáticas si te las deben.
        }

        // 2. Estado (Pendiente / Pagado / Parcial)
        if ($request->has('status')) {
             $query->where('status', $request->status);
        }

        // 3. Ocultar Pagados (Filtro Típico de "Por Cobrar")
        // Si el frontend envía include_paid=false, solo mostramos lo que se debe.
        if ($request->has('include_paid') && $request->include_paid == 'false') {
            $query->where('status', '!=', 'paid');
        }

        // 4. Filtros Específicos (Para ver historial de un solo cliente/proveedor)
        $query->when($request->entity_type, fn($q, $t) => $q->where('entity_type', $t)); 
        $query->when($request->entity_id, fn($q, $id) => $q->where('entity_id', $id));   
        $query->when($request->currency_code, fn($q, $c) => $q->where('currency_code', $c)); 

        // 5. Búsqueda Avanzada
        $query->when($request->search, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHasMorph('transaction', [
                        \App\Models\CurrencyExchange::class,
                        \App\Models\InternalTransaction::class
                    ], function ($q, $type) use ($search) {
                        if ($type === \App\Models\CurrencyExchange::class) {
                            $q->where('number', 'like', "%{$search}%");
                        } 
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

        // 6. Fechas
        $query->when($request->start_date, fn($q, $date) => $q->whereDate('created_at', '>=', $date));
        $query->when($request->end_date, fn($q, $date) => $q->whereDate('created_at', '<=', $date));

        return $query->latest()->paginate($request->per_page ?? 15)->withQueryString();
    }

    /**
     * 2. VISTA AGRUPADA (TOTALES POR PERSONA)
     * Suma todo lo que debe "Juan", "Pedro", etc.
     */
    public function groupedPayables(Request $request)
    {
        // Determinamos si buscamos Deudas (payable) o Cobros (receivable)
        $type = $request->type ?? 'payable';

        // Filtramos lo que NO esté pagado totalmente
        $query = LedgerEntry::where('type', $type)
            ->where('status', '!=', 'paid');

        // ✅ CORRECCIÓN: Aquí también quitamos el whereNull('transaction_type')
        // Para que las comisiones se sumen al total de la deuda del cliente.

        // Filtro opcional por moneda
        $query->when($request->currency_code, fn($q, $c) => $q->where('currency_code', $c));
        
        $grouped = $query->select(
                'entity_type',
                'entity_id',
                'currency_code',
                DB::raw('SUM(amount) as total_original_debt'),
                DB::raw('SUM(paid_amount) as total_paid'),
                DB::raw('SUM(amount - paid_amount) as total_pending'), // Lo que debe hoy
                DB::raw('COUNT(id) as movements_count'),
                DB::raw('MIN(due_date) as oldest_due_date')
            )
            ->groupBy('entity_type', 'entity_id', 'currency_code')
            ->with('entity')
            ->orderByDesc('total_pending')
            ->paginate(15);

        return response()->json($grouped);
    }

    /**
     * 3. CREAR MANUALMENTE (Préstamo / Deuda)
     */
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

        // Buscamos el ID de la moneda para guardar ambos datos (ID y Código)
        $currency = \App\Models\Currency::where('code', $validated['currency_code'])->first();

        $entry = LedgerEntry::create([
            'tenant_id'       => auth()->user()->tenant_id,
            'description'     => $validated['description'],
            'amount'          => $validated['amount'],
            'original_amount' => $validated['amount'],
            'currency_code'   => $validated['currency_code'],
            'currency_id'     => $currency ? $currency->id : null,
            'type'            => $validated['type'],
            'entity_type'     => $validated['entity_type'],
            'entity_id'       => $validated['entity_id'],
            'due_date'        => $validated['due_date'] ?? null,
            'status'          => 'pending',
            'paid_amount'     => 0
        ]);

        return response()->json($entry->load('entity'), 201);
    }

    /**
     * 4. RESUMEN / DASHBOARD
     */
    public function summary()
    {
        // Total Por Pagar (Incluye manuales y automáticas pendientes)
        $payable = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->sum(DB::raw('amount - paid_amount'));

        // Total Por Cobrar (Incluye manuales y automáticas pendientes)
        $receivableLedger = LedgerEntry::where('type', 'receivable')
            ->where('status', 'pending')
            ->sum(DB::raw('amount - paid_amount'));

        // Top 5 Acreedores
        $topPayables = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->select('entity_type', 'entity_id', DB::raw('SUM(amount - paid_amount) as total'))
            ->groupBy('entity_type', 'entity_id')
            ->with('entity')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'payable_total'    => $payable,
            'receivable_total' => $receivableLedger, // Ahora incluye todo lo pendiente real
            'top_debts'        => $topPayables,
        ]);
    }

    /**
     * 5. REGISTRAR PAGO (ABONO)
     */
    public function pay(Request $request, LedgerEntry $ledgerEntry)
    {
        $request->validate([
            'account_id'  => 'required|exists:accounts,id',
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
        ]);

        $account = Account::findOrFail($request->account_id);

        // Validación de moneda estricta
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