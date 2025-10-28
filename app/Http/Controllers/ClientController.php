<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Client::all()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:clients',
        ], [
            'name.required' => 'El nombre del cliente es obligatorio',
            'name.unique' => 'Este cliente ya existe',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        try {
            DB::beginTransaction();
            $client = Client::create($validator->validated());
            DB::commit();

            return response()->json([
                'message' => 'Cliente creado con éxito',
                'data' => $client
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al crear el cliente",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $client = Client::find($id);
        if (!$client) {
            return response()->json(["message" => "Cliente no encontrado"], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:clients,name,' . $id,
        ], [
            'name.required' => 'El nombre es obligatorio',
            'name.unique' => 'Ese nombre de cliente ya está en uso',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $client->update($validator->validated());
            DB::commit();

            return response()->json([
                'message' => 'Cliente actualizado con éxito',
                'data' => $client
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al actualizar el cliente", "error" => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $client = Client::find($id);
        if (!$client) {
            return response()->json(["message" => "Cliente no encontrado"], 404);
        }

        try {
            DB::beginTransaction();
            $client->delete();
            DB::commit();

            return response()->json(['message' => 'Cliente eliminado con éxito'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al eliminar el cliente", "error" => $e->getMessage()], 500);
        }
    }
}