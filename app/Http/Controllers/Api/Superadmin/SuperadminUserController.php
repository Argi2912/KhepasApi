<?php

namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperadminUserController extends Controller
{
    /**
     * Lista todos los usuarios del sistema con filtros.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search'    => 'nullable|string|max:100',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'role'      => 'nullable|string|max:50',
        ]);

        $query = User::with(['roles', 'tenant'])
            ->where('id', '!=', Auth::id()); // Excluir al super admin de la lista

        // Filtro de búsqueda (nombre o email)
        $query->when($request->search, function ($q, $term) {
            $term = "%{$term}%";
            return $q->where(fn($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        });

        // Filtro por tenant
        $query->when($request->tenant_id, function ($q, $tenantId) {
            return $q->where('tenant_id', $tenantId);
        });

        // Filtro por rol
        $query->when($request->role, function ($q, $roleName) {
            return $q->whereHas('roles', fn($rq) => $rq->where('name', $roleName));
        });

        $users = $query->latest()->paginate(15)->withQueryString();

        // Transformar para incluir info del rol y tenant
        $users->getCollection()->transform(function ($user) {
            return [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'is_active'  => $user->is_active,
                'tenant'     => $user->tenant ? $user->tenant->name : 'Sin Tenant',
                'tenant_id'  => $user->tenant_id,
                'role'       => $user->roles->first()?->name ?? 'sin_rol',
                'created_at' => $user->created_at,
            ];
        });

        return response()->json($users);
    }

    /**
     * Actualiza los datos de un usuario.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'email'     => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'is_active' => 'sometimes|boolean',
            'role'      => [
                'sometimes', 'required', 'string',
                Rule::exists('roles', 'name')->where(fn($q) => $q->where('name', '!=', 'superadmin')),
            ],
        ]);

        // Actualizar datos básicos
        $user->update($request->only('name', 'email', 'is_active'));

        // Actualizar rol si se envió
        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        return response()->json([
            'message' => "Usuario \"{$user->name}\" actualizado correctamente.",
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'is_active' => $user->is_active,
                'role'      => $user->roles->first()?->name ?? 'sin_rol',
            ],
        ]);
    }

    /**
     * Cambia la contraseña de un usuario.
     */
    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => "Contraseña de \"{$user->name}\" actualizada correctamente.",
        ]);
    }

    /**
     * Devuelve los datos del super admin autenticado.
     */
    public function profile()
    {
        $user = Auth::user();

        return response()->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'created_at' => $user->created_at,
        ]);
    }

    /**
     * Actualiza el perfil del super admin.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name'  => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => 'nullable|string|min:8',
        ]);

        $user->update($request->only('name', 'email'));

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        return response()->json([
            'message' => 'Perfil actualizado correctamente.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
