<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        // Asegura que todas las acciones usen el permiso 'manage users'
        $this->middleware('permission:manage users');
    }

    /**
     * Lista todos los usuarios (Base de Datos de Entidades).
     */
    public function index(Request $request): JsonResponse
    {
        // El Global Scope ya filtra por tenant_id
        $query = User::query()->with('roles');

        // --- 1. BÚSQUEDA GENERAL (SEARCH) ---
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('first_name', 'like', $searchTerm)
                  ->orWhere('last_name', 'like', $searchTerm)
                  ->orWhere('email', 'like', $searchTerm);
            });
        }

        // --- 2. FILTROS ESPECÍFICOS ---
        
        // Filtro por ROL (Clave para listar BD de Clientes, Corredores, etc.)
        if ($request->filled('role')) {
            $roleName = $request->role;
            $query->whereHas('roles', function ($q) use ($roleName) {
                $q->where('name', $roleName)->where('name', '!=', 'Super Admin'); 
            });
        }
        
        // Filtro por ESTADO (Activo/Inactivo)
        if ($request->filled('is_active')) {
            // Asegura que el valor sea booleano (true/false o 1/0)
            $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN); 
            $query->where('is_active', $isActive);
        }

        // --- 3. PAGINACIÓN ---
        $perPage = $request->get('per_page', 20);
        $users = $query->latest()->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Muestra el detalle de un usuario.
     */
    public function show(User $user): JsonResponse
    {
        // El Global Scope garantiza que el usuario pertenece al tenant
        return response()->json($user->load('roles'));
    }

    /**
     * Crea un nuevo usuario (Cliente, Proveedor, Corredor, Admin).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $tenantId = Auth::user()->tenant_id;
        $roleName = $validatedData['role_name'];

        try {
            // 1. Crear el usuario
            $user = User::create([
                'tenant_id' => $tenantId,
                'first_name' => $validatedData['first_name'],
                'last_name' => $validatedData['last_name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'phone_number' => $validatedData['phone_number'] ?? null,
                'address' => $validatedData['address'] ?? null,
                'date_of_birth' => $validatedData['date_of_birth'] ?? null,
                'is_active' => true,
                'is_admin' => false,
            ]);

            // 2. Asignar el rol
            $role = Role::where('name', $roleName)->firstOrFail();
            $user->assignRole($role);

            return response()->json(['message' => "Usuario '{$roleName}' creado exitosamente.", 'user' => $user->load('roles')], 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear el usuario.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualiza la información de un usuario.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validatedData = $request->validated();

        // 1. Actualizar campos de usuario
        $user->update([
            'first_name' => $validatedData['first_name'] ?? $user->first_name,
            'last_name' => $validatedData['last_name'] ?? $user->last_name,
            'phone_number' => $validatedData['phone_number'] ?? $user->phone_number,
            'address' => $validatedData['address'] ?? $user->address,
            'date_of_birth' => $validatedData['date_of_birth'] ?? $user->date_of_birth,
            'is_active' => $validatedData['is_active'] ?? $user->is_active,
            // Actualizar password solo si se proporciona y es diferente
            'password' => (isset($validatedData['password']) && !empty($validatedData['password'])) 
                          ? Hash::make($validatedData['password']) 
                          : $user->password,
        ]);

        // 2. Actualización de Rol (si se proporciona)
        if (isset($validatedData['role_name'])) {
            $role = Role::where('name', $validatedData['role_name'])->firstOrFail();
            // syncRoles remueve todos los roles existentes y asigna el nuevo.
            $user->syncRoles([$role->name]);
        }

        return response()->json(['message' => 'Usuario actualizado exitosamente.', 'user' => $user->load('roles')]);
    }

    /**
     * Elimina un usuario del almacenamiento.
     */
    public function destroy(User $user): JsonResponse
    {
        // 1. Prevenir la eliminación de sí mismo o del Super Admin
        if ($user->id === Auth::id() || $user->is_admin) {
             return response()->json(['message' => 'No puede eliminar su propia cuenta o un Super Admin.'], 403);
        }

        // 2. Eliminar roles y luego el usuario
        $user->syncRoles([]);
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado exitosamente.']);
    }
}