<?php
namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request; // Usaremos un Request simple aquí

class TenantController extends Controller
{
    // Nota: La autorización (role:superadmin) se maneja en las rutas.

    public function index()
    {
        return Tenant::paginate();
    }

    public function store(Request $request) // Reemplaza con StoreTenantRequest
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:tenants']);
        
        $tenant = Tenant::create($validated);
        
        return response()->json($tenant, 201);
    }

    public function show(Tenant $tenant)
    {
        return $tenant;
    }

    public function update(Request $request, Tenant $tenant) // Reemplaza con UpdateTenantRequest
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:tenants,name,'.$tenant->id]);
        
        $tenant->update($validated);
        
        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return response()->noContent();
    }
}