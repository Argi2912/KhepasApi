<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth; // <-- 1. AÑADIDO
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El campo de correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser una dirección de correo electrónico válida.',
            'email.unique' => 'El correo electrónico ya ha sido tomado.',
            'password.required' => 'El campo de contraseña es obligatorio.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();

            $data['password'] = bcrypt($data['password']);
            User::create($data); // <-- Usuario creado

            DB::commit();

            // --- 2. LÓGICA AÑADIDA ---
            // Intenta iniciar sesión con los datos originales (antes del hash)
            $credentials = $request->only('email', 'password');
            
            // Asumiendo que usas el guard 'api' (típico con JWT/Sanctum)
            if (! $token = auth('api')->attempt($credentials)) {
                // Si el login falla por alguna razón
                return response()->json(['error' => 'No autorizado después del registro'], 401);
            }

            // 3. Devolver el token que el frontend espera
            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60 
            ], 201); // 201 Creado (y logueado)
            
        } catch (\Throwable $th) {
            DB::rollBack();

            // Devuelve el mensaje de error real para depuración
            return response()->json(['error' => 'Error al registrar el usuario', 'details' => $th->getMessage()], 500);
        }
    }
}