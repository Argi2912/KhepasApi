<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\Request; // Reemplaza con Form Requests

class ProviderController extends Controller
{
    public function index(Request $request) // 2. Inyectar Request
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
            return $q->search($term); // Llama al scopeSearch()
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        // 6. Paginar
        return $query->latest()->paginate(15)->withQueryString();
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
}