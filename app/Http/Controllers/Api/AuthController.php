<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{

    /**
     * Inicia sesión y devuelve el token JWT.
     */
    public function login(Request $request)
    {
        // 1. Validar formato de entrada
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // 2. Intentar loguear (verifica email y contraseña)
        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Credenciales incorrectas'], 401);
        }

        // --- INICIO DE LA SOLUCIÓN DE BLOQUEO ---
        
        // 3. Obtener el usuario que acaba de pasar la validación de contraseña
        $user = Auth::guard('api')->user();

        // 4. Verificar si el USUARIO está desactivado (is_active = 0)
        // Nota: Asegúrate de que tu modelo User tenga 'is_active' en $fillable
        if ($user->is_active == false) {
            Auth::guard('api')->logout(); // Invalidar el token inmediatamente
            return response()->json(['error' => 'Su cuenta ha sido desactivada.'], 403);
        }

        // 5. Verificar si el TENANT (Empresa) está desactivado
        if ($user->tenant && $user->tenant->is_active == false) {
            Auth::guard('api')->logout(); // Invalidar el token inmediatamente
            return response()->json(['error' => 'Acceso denegado: Su organización se encuentra inactiva.'], 403);
        }

        // --- FIN DE LA SOLUCIÓN ---

        return $this->respondWithToken($token);
    }

    /**
     * Devuelve los datos del usuario autenticado.
     */
    public function me()
    {
        $user = auth()->user()->load([
            'roles' => function ($query) {
                // Dentro de la relación 'roles', cargamos la relación 'permissions'
                $query->with('permissions');
            },
            'tenant', // Cargamos la información del Tenant
        ]);

        return response()->json($user);
    }

    /**
     * Cierra la sesión del usuario (invalida el token).
     */
    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresca un token.
     */
    public function refresh()
    {
        return $this->respondWithToken(Auth::guard('api')->refresh());
    }

    /**
     * Devuelve la estructura de respuesta del token.
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            // Expira en (segundos), por defecto 3600 (1 hora)
            'expires_in'   => Auth::guard('api')->factory()->getTTL() * 60,
        ]);
    }
}