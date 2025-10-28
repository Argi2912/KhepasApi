<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Provider::all()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:providers',
        ], [
            'name.required' => 'El nombre del proveedor es obligatorio',
            'name.unique' => 'Este proveedor ya existe',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $provider = Provider::create($validator->validated());
            DB::commit();

            return response()->json(['message' => 'Proveedor creado con éxito', 'data' => $provider], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al crear el proveedor", "error" => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $provider = Provider::find($id);
        if (!$provider) {
            return response()->json(["message" => "Proveedor no encontrado"], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:providers,name,' . $id,
        ], [
            'name.required' => 'El nombre es obligatorio',
            'name.unique' => 'Ese nombre de proveedor ya está en uso',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $provider->update($validator->validated());
            DB::commit();

            return response()->json(['message' => 'Proveedor actualizado con éxito', 'data' => $provider], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al actualizar el proveedor", "error" => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $provider = Provider::find($id);
        if (!$provider) {
            return response()->json(["message" => "Proveedor no encontrado"], 404);
        }

        try {
            DB::beginTransaction();
            $provider->delete();
            DB::commit();

            return response()->json(['message' => 'Proveedor eliminado con éxito'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al eliminar el proveedor", "error" => $e->getMessage()], 500);
        }
    }
}