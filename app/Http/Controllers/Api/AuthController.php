<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRegistrationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }

    /**
     * Registra un nuevo usuario en el sistema.
     * Por defecto, el usuario se asigna al rol 'Client'.
     */
    public function register(UserRegistrationRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        try {
            // 1. Crear el usuario
            $user = User::create([
                'tenant_id' => $validatedData['tenant_id'],
                'first_name' => $validatedData['first_name'],
                'last_name' => $validatedData['last_name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'phone_number' => $validatedData['phone_number'] ?? null,
                'date_of_birth' => $validatedData['date_of_birth'] ?? null,
                'is_active' => true,
            ]);

            // 2. Asignar Rol: Buscar el rol 'Client' y asignarlo
            $roleName = $validatedData['role_name'] ?? 'Client';
            $role = Role::where('name', $roleName)->first();

            if (!$role) {
                // Esto no debería suceder si los seeders se ejecutan correctamente.
                throw new \Exception("Rol {$roleName} no encontrado.");
            }
            $user->assignRole($role);

            // 3. Autenticar y devolver token
            $token = Auth::guard('api')->attempt($request->only('email', 'password'));

            return $this->respondWithToken($token, 201);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Fallo en el registro.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtiene un JWT via las credenciales proporcionadas.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'No autorizado. Credenciales inválidas o usuario inactivo.'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Invalida el token del usuario (cierre de sesión).
     */
    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();
        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    /**
     * Refresca el token JWT.
     */
    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(Auth::guard('api')->refresh());
    }

    /**
     * Obtiene el usuario autenticado.
     */
    public function me(): JsonResponse
    {
        return response()->json(Auth::guard('api')->user());
    }

    /**
     * Estructura la respuesta con el token.
     */
    protected function respondWithToken(string $token, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60, // TTL en segundos
            'user' => Auth::guard('api')->user()
        ], $statusCode);
    }
}