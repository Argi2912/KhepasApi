<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Lista las deudas.
     * GET /api/ledger
     * Params opcionales: ?status=pending&type=payable
     */
    public function index(Request $request)
    {
        $query = LedgerEntry::query()
            ->with(['entity', 'transaction']); // Carga optimizada

        // Filtros dinámicos
        $query->when($request->status, fn($q, $s) => $q->where('status', $s));
        $query->when($request->type, fn($q, $t) => $q->where('type', $t));
        
        return $query->latest()->paginate(15);
    }

    /**
     * Resumen para Dashboard (Tarjetas Totales)
     * GET /api/ledger/summary
     */
    public function summary()
    {
        // Total que DEBEMOS pagar
        $payable = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->sum('amount');
        
        // Total que nos DEBEN cobrar
        $receivable = LedgerEntry::where('type', 'receivable')
            ->where('status', 'pending')
            ->sum('amount');

        // Top 5 Acreedores (A quién le debemos más)
        $topPayables = LedgerEntry::where('type', 'payable')
            ->where('status', 'pending')
            ->select('entity_type', 'entity_id', DB::raw('SUM(amount) as total'))
            ->groupBy('entity_type', 'entity_id')
            ->with('entity') // Carga el nombre del Broker/Provider
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'payable_total' => $payable,
            'receivable_total' => $receivable,
            'top_debts' => $topPayables
        ]);
    }

    /**
     * PAGAR UNA DEUDA
     * POST /api/ledger/{id}/pay
     * Body: { "account_id": 5 }
     */
    public function pay(Request $request, LedgerEntry $ledgerEntry)
    {
        $request->validate([
            'account_id' => 'required|exists:accounts,id' // Cuenta de donde sale el dinero
        ]);

        try {
            // Llamamos al servicio para hacer la transacción contable
            $tx = $this->transactionService->processDebtPayment(
                $ledgerEntry, 
                $request->account_id
            );

            return response()->json([
                'message' => 'Pago procesado correctamente',
                'transaction' => $tx
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}