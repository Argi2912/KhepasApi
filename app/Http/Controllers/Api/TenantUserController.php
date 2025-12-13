<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class TenantUserController extends Controller
{
    /**
     * Obtiene una lista simple de roles disponibles para el tenant.
     * EXPLICACIÓN: Esta función es necesaria para poblar el <BaseSelect>
     * en el formulario de creación/edición de usuarios. Solo devuelve 
     * roles que un administrador de tenant puede asignar (excluyendo 'superadmin').
     */
    public function getAvailableRoles()
    {
        // Excluir el rol 'superadmin' que es global y no debe ser asignado por un tenant
        $roles = Role::where('name', '!=', 'superadmin')
                     ->select('id', 'name')
                     ->get();
        
        // Devolvemos la lista simple directamente para el frontend
        return response()->json($roles);
    }

    public function index(Request $request)
    {
        // 1. Obtener el tenant_id del admin autenticado
        // Se mantiene el uso explícito de Auth para seguridad, aunque BelongsToTenant ya lo haga.
        $tenantId = Auth::guard('api')->user()->tenant_id;

        $request->validate([
            'search' => 'nullable|string|max:100',
            'role'   => 'nullable|string|max:50', // Para filtrar por rol
        ]);

        $query = User::query()
            ->with('roles')                  // Carga los roles (necesario para el UserResource)
            ->where('tenant_id', $tenantId); // FILTRO MANUAL OBLIGATORIO

        // Filtro de búsqueda
        $query->when($request->search, function ($q, $term) {
            $term = "%{$term}%";
            return $q->where(fn($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        });

        // Filtro por rol
        $query->when($request->role, function ($q, $roleName) {
            return $q->whereHas('roles', fn($rq) => $rq->where('name', $roleName));
        });

        // Paginar y envolver en el Resource
        $users = $query->latest()->paginate(15)->withQueryString();

        return UserResource::collection($users);
    }
    /**
     * Almacena un nuevo usuario (Corredor, Admin) PARA EL TENANT ACTUAL.
     */
    public function store(Request $request)
    {
        $tenantId = Auth::guard('api')->user()->tenant_id;

        // Validamos que el rol exista y NO sea 'superadmin'
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role'     => [ // 🚨 Campo 'role' usado por el frontend
                'required',
                'string',
                Rule::exists('roles', 'name')->where(function ($query) {
                    // Asegura que el rol no sea superadmin
                    $query->where('name', '!=', 'superadmin');
                }),
            ],
        ]);

        // Inicia transacción por si falla la asignación de rol
        $user = DB::transaction(function () use ($request, $tenantId) {
            $user = User::create([
                'tenant_id' => $tenantId, // <-- ASIGNACIÓN MANUAL DEL TENANT
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
            ]);

            $user->assignRole($request->role); // Usa el nombre del rol

            return $user;
        });

        // Usamos load('roles') ya que el store devuelve la respuesta del modelo, no del Resource Collection
        return new UserResource($user->load('roles'));
    }

    /**
     * Muestra un usuario específico (solo si es del mismo tenant).
     */
    public function show(User $user)
    {
        $tenantId = Auth::guard('api')->user()->tenant_id;

        // VALIDACIÓN DE PERTENENCIA AL TENANT
        if ($user->tenant_id !== $tenantId) {
            abort(404, 'Usuario no encontrado en este tenant.');
        }

        return new UserResource($user->load('roles'));
    }

    /**
     * Actualiza un usuario (nombre, email, rol) del tenant actual.
     */
    public function update(Request $request, User $user)
    {
        $tenantId = Auth::guard('api')->user()->tenant_id;

        // VALIDACIÓN DE PERTENENCIA AL TENANT
        if ($user->tenant_id !== $tenantId) {
            abort(404, 'Usuario no encontrado en este tenant.');
        }

        $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id), // Ignora el email actual del usuario
            ],
            'password' => 'nullable|string|min:8|confirmed', // Opcional
            'role'     => [ // 🚨 Campo 'role' usado por el frontend
                'sometimes',
                'required',
                'string',
                Rule::exists('roles', 'name')->where(function ($query) {
                    $query->where('name', '!=', 'superadmin');
                }),
            ],
        ]);

        // Inicia transacción
        DB::transaction(function () use ($request, $user) {
            // Actualiza datos básicos
            $user->update($request->only('name', 'email'));

            // Actualiza password solo si se envió
            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            // Actualiza el rol (re-sincroniza)
            if ($request->filled('role')) {
                $user->syncRoles([$request->role]); // Usa el nombre del rol
            }
        });

        return new UserResource($user->load('roles'));
    }

    /**
     * Elimina un usuario del tenant actual.
     * (No se puede eliminar a sí mismo).
     */
    public function destroy(User $user)
    {
        $tenantId      = Auth::guard('api')->user()->tenant_id;
        $currentUserId = Auth::guard('api')->id();

        // VALIDACIÓN DE PERTENENCIA AL TENANT
        if ($user->tenant_id !== $tenantId) {
            abort(404, 'Usuario no encontrado en este tenant.');
        }

        // VALIDACIÓN DE SEGURIDAD
        if ($user->id === $currentUserId) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], 403);
        }

        $user->delete();

        return response()->noContent();
    }
}