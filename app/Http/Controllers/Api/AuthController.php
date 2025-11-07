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
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

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
