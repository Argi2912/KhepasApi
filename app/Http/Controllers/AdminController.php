<?php

namespace App\Http\Controllers;

use App\Models\Admin; // ¡Usa el nuevo modelo Admin!
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Admin::all()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:admins',
        ], [
            'name.required' => 'El nombre del admin es obligatorio',
            'name.unique' => 'Este registro admin ya existe',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $admin = Admin::create($validator->validated());
            DB::commit();

            return response()->json(['message' => 'Admin creado con éxito', 'data' => $admin], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al crear el admin", "error" => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $admin = Admin::find($id);
        if (!$admin) {
            return response()->json(["message" => "Admin no encontrado"], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:admins,name,' . $id,
        ], [
            'name.required' => 'El nombre es obligatorio',
            'name.unique' => 'Ese nombre de admin ya está en uso',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $admin->update($validator->validated());
            DB::commit();

            return response()->json(['message' => 'Admin actualizado con éxito', 'data' => $admin], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al actualizar el admin", "error" => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $admin = Admin::find($id);
        if (!$admin) {
            return response()->json(["message" => "Admin no encontrado"], 404);
        }

        try {
            DB::beginTransaction();
            $admin->delete();
            DB::commit();

            return response()->json(['message' => 'Admin eliminado con éxito'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al eliminar el admin", "error" => $e->getMessage()], 500);
        }
    }
}