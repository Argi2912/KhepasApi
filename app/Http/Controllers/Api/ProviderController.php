<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\TransactionService;
use Illuminate\Http\Request;

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

        // Asegúrate de que aquí no estés usando un "Resource" que oculte el dato.
        // Si devuelves el modelo directo así, Laravel enviará todas las columnas visibles.
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'contact_person' => 'nullable', 'email' => 'nullable', 'phone' => 'nullable']);
        // Inicializamos el balance en 0 por seguridad
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

    // --- FUNCIÓN CORREGIDA ---
    public function addBalance(Request $request, Provider $provider)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'target_account_id' => 'nullable|exists:accounts,id',
        ]);

        // Determinar moneda desde la cuenta destino si fue proporcionada
        $currencyCode = 'USD';
        $account = null;
        if ($request->target_account_id) {
            $account = \App\Models\Account::lockForUpdate()->find($request->target_account_id);
            if ($account) {
                $currencyCode = $account->currency_code ?? 'USD';
            }
        }

        $amount = (float) $request->amount;
        $description = $request->description ?? 'Registro por pagar a proveedor';

        // 1. Incrementar saldo del proveedor + crear LedgerEntry + historial
        $this->transactionService->addBalanceToEntity(
            $provider,
            $amount,
            $currencyCode,
            $description
        );

        // 2. El dinero prestado ENTRA a la cuenta seleccionada
        if ($account) {
            $account->increment('balance', $amount);

            // Registrar la entrada en el historial de la cuenta
            \App\Models\InternalTransaction::create([
                'tenant_id' => \Illuminate\Support\Facades\Auth::user()->tenant_id ?? 1,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'account_id' => $account->id,
                'source_type' => 'account',
                'type' => 'income',
                'category' => 'Préstamo de Proveedor',
                'amount' => $amount,
                'description' => "{$description} - {$provider->name}",
                'transaction_date' => now(),
                'entity_type' => \App\Models\Provider::class,
                'entity_id' => $provider->id,
                'person_name' => $provider->name,
                'dueño' => $account->name,
            ]);
        }

        $provider->refresh();

        return response()->json($provider);
    }
}