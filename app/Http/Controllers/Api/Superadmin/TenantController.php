<?php
namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request; // Usaremos un Request simple aquí
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantController extends Controller
{

    public function dashboardStats()
    {
        return response()->json([
            'total_tenants'   => Tenant::count(),
            'active_tenants'  => Tenant::where('is_active', true)->count(),
            'inactive_tenants'=> Tenant::where('is_active', false)->count(),
            'total_users'     => User::count(), // Total de usuarios en todo el sistema
        ]);
    }

   /**
     * Lista los Tenants con su "Admin Principal" (asumimos el primero creado o por rol).
     */
    public function index()
    {
        // Traemos el tenant y filtramos sus usuarios para mostrar solo al admin_tenant
        $tenants = Tenant::with(['users' => function ($query) {
            $query->role('admin_tenant')->select('id', 'tenant_id', 'name', 'email');
        }])->latest()->paginate(10);

        return response()->json($tenants);
    }

    /**
     * Crea un Tenant y su primer Administrador en una sola transacción.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Datos del Negocio
            'name' => 'required|string|max:255|unique:tenants,name',
            // Datos del Admin
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                // 1. Crear Tenant
                $tenant = Tenant::create(['name' => $validated['name']]);

                // 2. Crear Usuario Admin vinculado al Tenant
                $admin = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => Hash::make($validated['password']),
                ]);

                // 3. Asignar Rol (Spatie)
                $admin->assignRole('admin_tenant');

                return [
                    'tenant' => $tenant,
                    'admin' => $admin
                ];
            });

            return response()->json([
                'message' => 'Tenant y Administrador creados exitosamente.',
                'data' => $result
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el tenant',
                'error' => $e->getMessage()
            ], 500);
        }
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

    public function destroy($id)
    {
        $tenant = Tenant::findOrFail($id);

        // Opcional: Evitar borrar el tenant principal o de demostración
        if ($tenant->id === 1 || $tenant->domain === 'demo') {
            return response()->json(['message' => 'No puedes eliminar el tenant principal/demo.'], 403);
        }

        $tenant->delete();

        return response()->noContent();
    }

    public function toggleStatus(Tenant $tenant)
    {
        // Invertimos el estado
        $tenant->is_active = !$tenant->is_active;
        $tenant->save();

        $status = $tenant->is_active ? 'ACTIVADO' : 'DESACTIVADO';

        return response()->json([
            'message' => "El tenant '{$tenant->name}' ha sido {$status}.",
            'is_active' => $tenant->is_active
        ]);
    }
}