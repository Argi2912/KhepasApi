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
            'name' => 'required|string|max:50',
        ], [
            'name.required' => 'El nombre de la plataforma es obligatorio',
            'name.string' => 'El nombre de la plataforma debe ser una cadena de caracteres',
            'name.max' => 'El nombre de la plataforma debe tener un máximo de 50 caracteres',
        ]);
        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);

            try {
                DB::beginTransaction();
                $platform = Platform::create([
                    'name' => $request->name,
                ]);

                return response()->json([
                    'message' => 'Plataforma creada con exito',
                    'data' => $platform
                ], 201);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    "message" => "Error al crear la plataforma",
                    "error" => $e->getMessage()
                ], 500);
            }
        }
    }

    public function update(Request $request, $id)
    {
        $platform = Platform::find($id);

        if (!$platform) {
            return response()->json([
                "message" => "Plataforma no encontrada"
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
        ], [
            'name.required' => 'El nombre de la plataforma es obligatorio',
            'name.string' => 'El nombre de la plataforma debe ser una cadena de caracteres',
            'name.max' => 'El nombre de la plataforma debe tener un máximo de 50 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        try {
            DB::beginTransaction();

            $platform->name = $request->name;
            $platform->save();

            return response()->json([
                'message' => 'Plataforma actualizada con exito',
                'data' => $platform
            ], 200);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al actualizar la plataforma",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function destroy($id){
        $platform = Platform::find($id);

        if (!$platform) {
            return response()->json([
                "message" => "Plataforma no encontrada"
            ], 404);
        }

        try {
            DB::beginTransaction();

            $platform->delete();

            return response()->json([
                'message' => 'Plataforma eliminada con exito'
            ], 200);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al eliminar la plataforma",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
