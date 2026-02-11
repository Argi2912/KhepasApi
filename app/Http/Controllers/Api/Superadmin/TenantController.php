<?php

namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantController extends Controller
{
    public function dashboardStats()
    {
        return response()->json([
            'total_tenants'    => Tenant::count(),
            'active_tenants'   => Tenant::where('is_active', true)->count(),
            'inactive_tenants' => Tenant::where('is_active', false)->count(),
            'total_users'      => User::count(),
        ]);
    }

    public function index()
    {
        $tenants = Tenant::with('admin')->latest()->paginate(10);
        return response()->json($tenants);
    }

    /**
     * Crea un Tenant con plan pre-seleccionado.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:tenants,name',
            'admin_name'  => 'required|string|max:255',
            'admin_email' => 'required|email|max:255|unique:users,email',
            'password'    => 'required|string|min:8',
            'plan'        => 'required|in:basic,pro', // Validamos el plan elegido
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                // Definimos la lógica de precios para que quede guardada de una vez
                $planPrice = ($validated['plan'] === 'pro') ? 29.99 : 10.00;
                $planName  = ($validated['plan'] === 'pro') ? 'Plan Profesional' : 'Plan Básico';

                // 1. Crear Tenant con el plan que usará a futuro
                $tenant = Tenant::create([
                    'name'                 => $validated['name'],
                    'is_active'            => true,
                    'plan_name'            => $planName,
                    'plan_price'           => $planPrice,
                    // Le damos 1 mes de cortesía. Si quieres 1 año, cambia addMonth() por addYear()
                    'subscription_ends_at' => now()->addMonth(), 
                ]);

                // 2. Crear Usuario Admin
                $admin = User::create([
                    'tenant_id' => $tenant->id,
                    'name'      => $validated['admin_name'],
                    'email'     => $validated['admin_email'],
                    'password'  => Hash::make($validated['password']),
                    'is_active' => true
                ]);

                // 3. Asignar Rol
                $admin->assignRole('admin_tenant');

                return ['tenant' => $tenant, 'admin' => $admin];
            });

            return response()->json([
                'message' => 'Tenant creado exitosamente con el plan ' . $validated['plan'],
                'data'    => $result
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Tenant $tenant)
    {
        return $tenant->load('admin');
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:tenants,name,'.$tenant->id]);
        $tenant->update($validated);
        return response()->json($tenant);
    }

    public function destroy($id)
    {
        $tenant = Tenant::findOrFail($id);
        if ($tenant->id === 1) {
            return response()->json(['message' => 'No puedes eliminar el tenant principal.'], 403);
        }
        $tenant->delete();
        return response()->noContent();
    }

    public function toggleStatus(Tenant $tenant)
    {
        $newStatus = !$tenant->is_active;
        $updateData = ['is_active' => $newStatus];

        if ($newStatus) {
            // Si lo reactivas manualmente, le damos otro mes de vida
            $updateData['subscription_ends_at'] = now()->addMonth();
        }

        $tenant->update($updateData);

        return response()->json([
            'message'   => "El tenant '{$tenant->name}' ha sido actualizado.",
            'is_active' => $tenant->is_active
        ]);
    }
}