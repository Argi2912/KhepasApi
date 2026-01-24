<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalTransaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            // Traemos relaciones
            ->with(['user:id,name', 'account:id,name,currency_code', 'entity']);

        $query->when($request->account_id, fn($q, $id) => $q->where('account_id', $id));
        $query->when($request->type, fn($q, $type) => $q->where('type', $type));

        // Paginamos
        $paginator = $query->latest('transaction_date')->paginate(15);

        // 🛠️ TRANSFORMACIÓN DE DATOS
        $paginator->getCollection()->transform(function ($tx) {
            return $this->fixDeletedData($tx);
        });

        return $paginator;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'source_type'      => 'nullable|in:account,investor,provider',
            'account_id'       => 'nullable', 
            'type'             => 'required|in:income,expense',
            'amount'           => 'required|numeric|min:0.01',
            'category'         => 'required',
            'transaction_date' => 'nullable|date',
            'entity_type'      => 'nullable|string',
            'entity_id'        => 'nullable|integer',
            'description'      => 'nullable',
            'dueño'            => 'nullable',     // Texto de respaldo
            'person_name'      => 'nullable',     // Texto de respaldo
        ]);

        $validated['user_id'] = Auth::id(); 
        $validated['source_type'] = $validated['source_type'] ?? 'account';

        try {
            $transaction = $this->transactionService->createInternalTransaction($validated);
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show($id)
    {
        $tx = InternalTransaction::withoutGlobalScopes()
            ->with(['user', 'account', 'entity'])
            ->find($id);

        if (!$tx) return response()->json(['message' => 'No encontrado'], 404);
        
        return response()->json($this->fixDeletedData($tx));
    }

    /**
     * LÓGICA CORREGIDA PARA MOSTRAR BILLETERAS VIRTUALES
     */
    private function fixDeletedData($tx)
    {
        // 1. ARREGLAR CUENTA (Bancaria o Virtual)
        if (!$tx->account) {
            
            $virtualName = 'Cuenta Eliminada'; // Valor por defecto
            $currency = '---';

            // CASO A: Es Proveedor (Billetera)
            if ($tx->entity_type && str_contains($tx->entity_type, 'Provider')) {
                // Busamos nombre real en la entidad, si no, usamos el texto guardado
                $name = $tx->entity ? $tx->entity->name : ($tx->person_name ?? 'Proveedor');
                $virtualName = "Billetera: $name";
                $currency = 'USD'; // Asumimos USD para virtuales
            }
            // CASO B: Es Inversionista (Capital)
            elseif ($tx->entity_type && str_contains($tx->entity_type, 'Investor')) {
                $name = $tx->entity ? $tx->entity->name : ($tx->person_name ?? 'Inversionista');
                $virtualName = "Capital: $name";
                $currency = 'USD';
            }
            // CASO C: Fallback Manual (Texto 'dueño')
            elseif ($tx->dueño) {
                $virtualName = $tx->dueño; 
            }

            // Inyectamos el objeto falso en la relación 'account'
            $tx->setRelation('account', (object)[
                'id' => null,
                'name' => $virtualName,
                'currency_code' => $currency
            ]);
        }

        // 2. ARREGLAR TERCERO/ENTIDAD (Cliente/Proveedor eliminado)
        if (!$tx->entity) {
            $tx->setRelation('entity', (object)[
                'id' => null,
                'name' => $tx->person_name ?? 'Desconocido/Eliminado'
            ]);
        }

        // 3. ARREGLAR USUARIO (Si el empleado fue borrado)
        if (!$tx->user) {
            $tx->setRelation('user', (object)[
                'id' => null,
                'name' => 'Usuario Ex-Empleado'
            ]);
        }

        return $tx;
    }
}