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
        // 1. VALIDACIÓN
        $validated = $request->validate([
            // CAMBIO: Lo hacemos 'nullable' (opcional). 
            // Si no viene, asumiremos que es 'account' más abajo.
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

        // 2. VALORES POR DEFECTO (Aquí está la magia)
        // Si no envías source_type, asumimos automáticamente que es 'account' (Tu Banco)
        $sourceType = $validated['source_type'] ?? 'account';
        
        $amount     = (float) $validated['amount'];
        $sourceId   = $validated['account_id'];
        $type       = $validated['type']; 

        // Rellenamos el array validado con el valor por defecto para que el Servicio lo entienda
        $validated['source_type'] = $sourceType; 

        // 3. VALIDACIÓN DE FONDOS (Solo para cuando sacas dinero)
        if ($type === 'expense') {
            
            // CASO: Cuentas Bancarias (Por defecto)
            if ($sourceType === 'account') {
                $account = Account::find($sourceId);
                
                if (!$account) {
                    return response()->json(['message' => 'La cuenta no existe.'], 422);
                }

                // Aquí validamos que tengas dinero real en el banco
                if ($account->balance < $amount) {
                    return response()->json([
                        'message' => "⛔ SALDO INSUFICIENTE. La cuenta {$account->name} solo tiene: " . number_format($account->balance, 2)
                    ], 422);
                }
            }
            // (Mantenemos la lógica de Inversionistas/Proveedores oculta pero funcional por si acaso)
            elseif ($sourceType === 'investor') {
                $investor = Investor::find($sourceId);
                if ($investor && $investor->available_balance < $amount) {
                     return response()->json(['message' => "Saldo insuficiente (Inversionista)."], 422);
                }
            }
            elseif ($sourceType === 'provider') {
                $provider = Provider::find($sourceId);
                if ($provider && $provider->available_balance < $amount) {
                     return response()->json(['message' => "Saldo insuficiente (Proveedor)."], 422);
                }
            }
        }

        // 4. PROCESAR
        try {
            return DB::transaction(function () use ($validated, $amount, $sourceType, $sourceId, $type) {
                
                // Si es Banco, actualizamos saldo físico
                if ($sourceType === 'account') {
                    if ($type === 'expense') {
                        Account::where('id', $sourceId)->decrement('balance', $amount);
                    } else {
                        Account::where('id', $sourceId)->increment('balance', $amount);
                    }
                }

                // Si transfieres a otra cuenta tuya (Destino)
                $destType = $validated['entity_type'] ?? null;
                $destId = $validated['entity_id'] ?? null;

                if ($type === 'expense' && $destType === 'App\Models\Account') {
                     Account::where('id', $destId)->increment('balance', $amount);
                }

                // Guardar Historial
                $transaction = $this->transactionService->createInternalTransaction($validated);

                return response()->json($transaction, 201);
            });

        } catch (\Exception $e) {
            Log::error("Error Transaction: " . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $tx = InternalTransaction::withoutGlobalScopes()->with('entity')->find($id);
        if (!$tx) return response()->json(['message' => 'No encontrado'], 404);
        return response()->json($tx);
    }
}