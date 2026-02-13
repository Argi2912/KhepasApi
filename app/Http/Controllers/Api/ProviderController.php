<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Account;
use App\Models\InternalTransaction;
use App\Models\LedgerEntry; // <--- IMPORTANTE
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ProviderController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        $query = Provider::query();

        if ($request->search) {
            $query->search($request->search);
        }

        // 'current_balance' se calcula automáticamente gracias a $appends en el modelo
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        // 🔥 MODIFICADO: Agregamos la validación del boolean
        $data = $request->validate([
            'name' => 'required|string|max:255', 
            'contact_person' => 'nullable', 
            'email' => 'nullable', 
            'phone' => 'nullable',
            'is_commission_informative' => 'boolean'
        ]);
        
        $data['available_balance'] = 0;
        return response()->json(Provider::create($data), 201);
    }

    public function show(Provider $provider)
    {
        return $provider;
    }

    public function update(Request $request, Provider $provider)
    {
        $provider->update($request->all());
        return response()->json($provider);
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();
        return response()->noContent();
    }

    /**
     * Registra Operación Compuesta:
     * 1. Recibe dinero en Caja (Activo)
     * 2. Registra Deuda en Libro Mayor (Pasivo/Por Pagar)
     */
    public function addBalance(Request $request, Provider $provider)
    {
        $request->validate([
            'amount_received'   => 'required|numeric|min:0.01',
            'target_account_id' => 'required|exists:accounts,id',
            'debt_amount'       => 'required|numeric|min:0.01',
            'debt_currency_id'  => 'required|exists:currencies,id',
            'transaction_date'  => 'required|date',
            'description'       => 'nullable|string',
            'interest_percentage' => 'nullable|numeric'
        ]);

        DB::beginTransaction();
        try {
            $user = Auth::user();
            $tenantId = $user->tenant_id ?? 1;

            // =========================================================
            // 1. CAJA: ENTRADA DE DINERO (Aumenta Saldo Cuenta)
            // =========================================================
            $account = Account::lockForUpdate()->findOrFail($request->target_account_id);
            $account->increment('balance', $request->amount_received);

            // Historial Caja
            InternalTransaction::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $user->id,
                'account_id'       => $account->id,
                'currency_id'      => $account->currency_id,
                'amount'           => $request->amount_received,
                'type'             => 'income', 
                'category'         => 'Préstamo de Proveedor',
                'description'      => "Entrada por financiamiento: {$request->description}",
                'transaction_date' => $request->transaction_date,
                'entity_type'      => Provider::class,
                'entity_id'        => $provider->id,
                'person_name'      => $provider->name,
            ]);

            // =========================================================
            // 2. PROVEEDOR: REGISTRO DE DEUDA (Por Pagar)
            // =========================================================
            
            // A. Historial del Proveedor (Visual)
            $provider->internalTransactions()->create([
                'tenant_id'        => $tenantId,
                'user_id'          => $user->id,
                'account_id'       => null,
                'currency_id'      => $request->debt_currency_id,
                'amount'           => $request->debt_amount,
                'type'             => 'income', // Deuda a favor del proveedor
                'category'         => 'Deuda con Proveedor',
                'description'      => "{$request->description} (Recibido: {$request->amount_received}, Interés: {$request->interest_percentage}%)",
                'transaction_date' => $request->transaction_date,
            ]);

            // B. LIBRO MAYOR (LedgerEntry) - ¡ESTO ACTUALIZA EL SALDO POR PAGAR!
            LedgerEntry::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $user->id,
                'entity_type'      => Provider::class,
                'entity_id'        => $provider->id,
                'type'             => 'payable', // Tipo 'payable' suma al saldo "Por Pagar"
                'status'           => 'pending',
                'currency_id'      => $request->debt_currency_id,
                'amount'           => $request->debt_amount,
                'paid_amount'      => 0,
                'description'      => "Financiamiento: " . $request->description,
                'transaction_date' => $request->transaction_date,
                'due_date'         => null 
            ]);

            // 🔥 MODIFICADO: Aumenta el saldo DISPONIBLE (Fondeo) del proveedor
            $provider->increment('available_balance', $request->amount_received);

            DB::commit();
            
            return response()->json(['message' => 'Financiamiento y Deuda registrados correctamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}