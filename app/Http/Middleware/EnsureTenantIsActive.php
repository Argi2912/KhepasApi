<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    /**
     * Revisa en CADA petición si el usuario o su empresa siguen activos.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('api')->user();

        if ($user) {
            // 1. Verificar si el USUARIO fue desactivado (Mantenemos logout)
            if (!$user->is_active) {
                Auth::guard('api')->logout();
                return response()->json(['error' => 'Sesión cerrada: Su usuario ha sido desactivado.'], 401);
            }

            // 2. Verificar si el TENANT fue desactivado
            // CAMBIO: No cerramos sesión. Devolvemos 402 Payment Required.
            if ($user->tenant && !$user->tenant->is_active) {
                return response()->json([
                    'code' => 'TENANT_INACTIVE',
                    'message' => 'Suscripción inactiva.'
                ], 402); 
            }
        }

        return $next($request);
    }
}