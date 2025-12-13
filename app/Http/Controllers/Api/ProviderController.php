<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\TransactionService; // <--- 1. IMPORTAR SERVICIO
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    protected $transactionService; // <--- 2. PROPIEDAD

    // 3. CONSTRUCTOR PARA INYECTAR EL SERVICIO
    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request)
    {
        // 3. Validar
        $request->validate([
            'search' => 'nullable|string|max:100',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 4. Iniciar consulta
        $query = Provider::query();

        // 5. Aplicar scopes
        $query->when($request->search, function ($q, $term) {
            return $q->search($term);
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        // 6. Paginar y Adjuntar Saldo (CAMBIO AQUÍ)
        $providers = $query->latest()->paginate(15)->withQueryString();

        // Recorremos los resultados para agregar el saldo "al vuelo"
        $providers->getCollection()->transform(function ($provider) {
            $provider->current_balance = $provider->available_balance;
            return $provider;
        });

        return $providers;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);
        
        $provider = Provider::create($validated);
        
        return response()->json($provider, 201);
    }

    public function show(Provider $provider)
    {
        // Adjuntar saldo antes de devolver (CAMBIO AQUÍ)
        $provider->current_balance = $provider->available_balance;
        
        return $provider;
    }

    public function update(Request $request, Provider $provider)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);
        
        $provider->update($validated);
        
        return response()->json($provider);
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();
        return response()->noContent();
    }

    /**
     * NUEVO METODO: Agregar saldo manualmente
     */
    public function addBalance(Request $request, Provider $provider)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255'
        ]);

        $this->transactionService->addBalanceToEntity(
            $provider, 
            $request->amount, 
            $request->description ?? 'Carga de saldo manual'
        );

        return response()->json([
            'message' => 'Saldo agregado correctamente',
            'new_balance' => $provider->available_balance
        ]);
    }
}