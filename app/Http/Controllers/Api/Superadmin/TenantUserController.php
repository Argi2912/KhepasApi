<?php

namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request; // Reemplaza con StoreTenantUserRequest
use Illuminate\Support\Facades\Hash;

class TenantUserController extends Controller
{
    /**
     * Lista todos los usuarios de todos los tenants (Vista Superadmin).
     */
    public function index(Request $request)
    {
        $query = User::with(['tenant', 'roles'])->latest();

        if ($request->filled('search')) {
            $term = "%{$request->search}%";
            $query->where(function($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        return $query->paginate(20);
    }

    /**
     * Crea el primer usuario (Admin) para un Tenant específico.
     */
    public function store(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id, // Asigna el Tenant
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole('admin'); // Asigna el rol de Admin (de Spatie)

        return response()->json($user, 201);
    }
}