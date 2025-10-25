<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RequestType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RequestTypeController extends Controller
{
    public function index()
    {
        $requestTypes = RequestType::all();
        if (!$requestTypes) {
            return response()->json([
                "message" => "Tipos de solicitud no encontrados"
            ], 404);
        }
        return response()->json([
            "message" => "Tipos de solicitud encontrados",
            "data" => $requestTypes
        ], 200);
    }

    public function store(Request $request)
    {
        $Validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
        ], [
            'name.required' => 'El nombre del tipo de solicitud es obligatorio',
            'name.string' => 'El nombre del tipo de solicitud debe ser una cadena de caracteres',
            'name.max' => 'El nombre del tipo de solicitud debe tener un máximo de 50 caracteres',
        ]);
        if ($Validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $Validator->errors()
            ], 400);
        }

        try {
            DB::beginTransaction();
            $requestType = RequestType::create([
                'name' => $request->name,
            ]);

            DB::commit();


            return response()->json([
                'message' => 'Tipo de solicitud creado con exito',
                'data' => $requestType
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al crear el tipo de solicitud",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
        ], [
            'name.required' => 'El nombre del tipo de solicitud es obligatorio',
            'name.string' => 'El nombre del tipo de solicitud debe ser una cadena de caracteres',
            'name.max' => 'El nombre del tipo de solicitud debe tener un máximo de 50 caracteres',
        ]);
        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        try {
            DB::beginTransaction();
            $requestType = RequestType::find($id);
            if (!$requestType) {
                return response()->json([
                    "message" => "Tipo de solicitud no encontrado"
                ], 404);
            }
            $requestType->update([
                'name' => $request->name,
            ]);
            DB::commit();

            return response()->json([
                'message' => 'Tipo de solicitud actualizado con exito',
                'data' => $requestType
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al actualizar el tipo de solicitud",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function destroy($id)
    {
        $requestType = RequestType::find($id);
        if (!$requestType) {
            return response()->json([
                "message" => "Tipo de solicitud no encontrado"
            ], 404);
        }
        try {
            DB::beginTransaction();
            $requestType->delete();
            DB::commit();

            return response()->json([
                "message" => "Tipo de solicitud eliminado con exito"
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al eliminar el tipo de solicitud",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
