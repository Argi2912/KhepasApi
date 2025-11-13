<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Platform; // 🚨 1. Importar el Modelo
use Illuminate\Http\Request;
use Illuminate\Validation\Rule; // Para validaciones (si es necesario)

class PlatformController extends Controller
{
    /**
     * Muestra un listado de todas las plataformas.
     * (El Trait 'BelongsToTenant' se encarga de filtrar por tenant).
     */
    public function index()
    {
        // Devolvemos todas las plataformas del tenant
        return Platform::latest()->get();
    }

    /**
     * Guarda una nueva plataforma en la base de datos.
     */
    public function store(Request $request)
    {
        // 1. Validar la entrada
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        // 2. Crear la plataforma
        // (El Trait 'BelongsToTenant' añadirá el 'tenant_id' automáticamente)
        $platform = Platform::create($validatedData);

        // 3. Devolver el recurso creado (código 201)
        // Esto es lo que tu frontend espera en response.data
        return response()->json($platform, 201);
    }

    /**
     * Muestra una plataforma específica.
     * Usamos Route-Model Binding: Laravel busca la plataforma por el ID
     * y la inyecta automáticamente.
     */
    public function show(Platform $platform)
    {
        // Devolver la plataforma encontrada
        // Esto es lo que tu frontend espera en response.data
        return $platform;
    }

    /**
     * Actualiza una plataforma específica.
     */
    public function update(Request $request, Platform $platform)
    {
        // 1. Validar la entrada (similar a store)
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        // 2. Actualizar la plataforma
        $platform->update($validatedData);

        // 3. Devolver el recurso actualizado
        // Esto es lo que tu frontend espera en response.data
        return response()->json($platform);
    }

    /**
     * Elimina una plataforma.
     */
    public function destroy(Platform $platform)
    {
        $platform->delete();

        // Devolver una respuesta "Sin Contenido" (código 204)
        return response()->noContent();
    }
}