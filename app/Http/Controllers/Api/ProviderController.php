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

        // El modelo Provider calcula los saldos automáticamente.
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'contact_person' => 'nullable', 'email' => 'nullable', 'phone' => 'nullable']);
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
            'description' => 'nullable|string|max:255'
        ]);

        // AQUÍ ESTABA EL ERROR: Faltaba enviar 'USD' como tercer parámetro.
        $this->transactionService->addBalanceToEntity(
            $provider, 
            $request->amount, 
            'USD', 
            $request->description ?? 'Carga de saldo manual'
        );

        return response()->json([
            'message' => 'Saldo agregado correctamente',
            'new_balance' => $provider->available_balance
        ]);
    }
}