<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request; // Reemplaza con StoreClientRequest y UpdateClientRequest

class ClientController extends Controller
{
    // La autorización (permission:...) se maneja en las rutas.

    public function index(Request $request) // 2. Inyectar Request
    {
        // 3. Validar filtros
        $request->validate([
            'search' => 'nullable|string|max:100',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 4. Iniciar consulta
        $query = Client::query();

        // 5. Aplicar scopes con when()
        $query->when($request->search, function ($q, $term) {
            return $q->search($term); // Llama al scopeSearch()
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date); // Llama al scopeFromDate() del Trait
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date); // Llama al scopeToDate() del Trait
        });

        // 6. Paginar
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request) // Usa StoreClientRequest
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'details' => 'nullable|string',
        ]);
        
        // El trait BelongsToTenant inyecta 'tenant_id' automáticamente
        $client = Client::create($validated);
        
        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        // El TenantScope ya previno que se acceda a IDs de otros tenants
        return $client;
    }

    public function update(Request $request, Client $client) // Usa UpdateClientRequest
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'details' => 'nullable|string',
        ]);
        
        $client->update($validated);
        
        return response()->json($client);
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return response()->noContent();
    }
}