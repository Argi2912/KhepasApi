<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\CurrencyExchange; // <--- 1. IMPORTAR MODELO
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator; // <--- 2. IMPORTAR PAGINADOR

class LedgerEntryController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Lista las deudas y AHORA TAMBIÉN las compras pendientes si es 'receivable'.
     */
    public function index(Request $request)
    {
        // 🚨 LÓGICA HÍBRIDA: Si buscamos "Por Cobrar" (Receivable) Pendientes
        if ($request->type === 'receivable' && $request->status === 'pending') {
            
            // A. Obtener Asientos Contables (Lo normal)
            $ledgerEntries = LedgerEntry::where('type', 'receivable')
                ->where('status', 'pending')
                ->with(['entity', 'transaction'])
                ->latest()
                ->get();

            // B. Obtener Compras de Divisas Pendientes
            $pendingPurchases = CurrencyExchange::where('status', 'pending')
                ->whereNotNull('buy_rate')
                ->where('buy_rate', '>', 0)
                ->with('client') // El cliente es la entidad
                ->latest()
                ->get()
                ->map(function ($exchange) {
                    // Transformamos la compra para que "parezca" un asiento contable en el JSON
                    $exchange->is_exchange_op = true; // Bandera para el Frontend
                    $exchange->description = "Compra Divisas (Pendiente de Entrega)";
                    $exchange->amount = $exchange->amount_received; // Usamos el monto de entrada
                    $exchange->entity = $exchange->client; // Asignamos el cliente como entidad
                    $exchange->transaction = (object) [
                        'number' => $exchange->number
                    ];
                    return $exchange;
                });

            // C. Fusionar y Ordenar por fecha
            $merged = $ledgerEntries->concat($pendingPurchases)->sortByDesc('created_at');

            // D. Paginación Manual (Porque estamos mezclando dos tablas)
            $page = $request->input('page', 1);
            $perPage = 15;
            $total = $merged->count();
            $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

            return new LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        // --- Comportamiento Original para "Por Pagar" o Historial ---
        $query = LedgerEntry::query()
            ->with(['entity', 'transaction']);

        $query->when($request->status, fn($q, $s) => $q->where('status', $s));
        $query->when($request->type, fn($q, $t) => $q->where('type', $t));
        
        return $query->latest()->paginate(15);
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

        // 🚨 NUEVO: Sumar Compras Pendientes
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
            'payable_total' => $payable,
            'receivable_total' => $receivableLedger + $receivablePurchases, // Suma Unificada
            'top_debts' => $topPayables
        ]);
    }

    // ... método pay sin cambios ...
    public function pay(Request $request, LedgerEntry $ledgerEntry)
    {
        $request->validate(['account_id' => 'required|exists:accounts,id']);
        try {
            $tx = $this->transactionService->processDebtPayment($ledgerEntry, $request->account_id);
            return response()->json(['message' => 'Pago procesado correctamente', 'transaction' => $tx]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}