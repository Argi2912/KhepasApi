<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PlatformController extends Controller
{
    public function index()
    {
        $platForms = Platform::all();

        if (!$platForms) {
            return response()->json([
                "message" => "Plataformas no encontradas"
            ], 404);
        }
        return response()->json([
            "message" => "Plataformas encontradas",
            "data" => $platForms
        ], 200);
    }
    public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:50|unique:platforms', // Te recomiendo añadir 'unique'
    ], [
        'name.required' => 'El nombre de la plataforma es obligatorio',
        'name.string' => 'El nombre de la plataforma debe ser una cadena de caracteres',
        'name.max' => 'El nombre de la plataforma debe tener un máximo de 50 caracteres',
        'name.unique' => 'Esta plataforma ya existe', // Mensaje para 'unique'
    ]);

    // 1. SI LA VALIDACIÓN FALLA, retorna el error y TERMINA
    if ($validator->fails()) {
        return response()->json([
            "message" => "Error de validación",
            "errors" => $validator->errors()
        ], 400); // 400 es mejor para validación que 422 si lo prefieres
    }

    // 2. SI LA VALIDACIÓN PASA, el código continúa aquí (FUERA DEL IF)
    try {
        DB::beginTransaction();
        
        $platform = Platform::create([
            'name' => $request->name,
        ]);

        // 3. Haces commit ANTES de retornar
        DB::commit(); 

        // 4. Retornas la plataforma creada (tu store de Pinia espera esto)
        return response()->json([
            'message' => 'Plataforma creada con exito',
            'data' => $platform // Esto es lo que usará unshift()
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            "message" => "Error al crear la plataforma",
            "error" => $e->getMessage()
        ], 500);
    }
}

    public function update(Request $request, $id)
    {
        // 1. Primero, encontrar la plataforma
        $platform = Platform::find($id);

        if (!$platform) {
            return response()->json(["message" => "Plataforma no encontrada"], 404);
        }

        // 2. Validar los datos (similar a store)
        $validator = Validator::make($request->all(), [
            // Regla 'unique' especial: ignora el ID actual
            'name' => 'required|string|max:50|unique:platforms,name,' . $id,
        ], [
            'name.required' => 'El nombre es obligatorio',
            'name.string' => 'El nombre debe ser una cadena de caracteres',
            'name.max' => 'El nombre debe tener un máximo de 50 caracteres',
            'name.unique' => 'Ese nombre de plataforma ya está en uso',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        // 3. Intentar actualizar (esto funciona gracias a $fillable)
        try {
            DB::beginTransaction();

            $platform->update([
                'name' => $request->name,
            ]);

            DB::commit();

            // 4. Devolver la plataforma actualizada
            return response()->json([
                'message' => 'Plataforma actualizada con exito',
                'data' => $platform
            ], 200); // 200 OK

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al actualizar la plataforma",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function destroy($id)
    {
        // 1. Encontrar la plataforma
        $platform = Platform::find($id);

        if (!$platform) {
            return response()->json(["message" => "Plataforma no encontrada"], 404);
        }

        // 2. Intentar eliminar
        try {
            DB::beginTransaction();

            $platform->delete();

            DB::commit();

            // 3. Devolver éxito (no es necesario devolver datos)
            return response()->json([
                'message' => 'Plataforma eliminada con exito'
            ], 200); // 200 OK (o 204 No Content)

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al eliminar la plataforma",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
