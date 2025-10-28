<?php

namespace App\Http\Controllers;

use App\Models\Corredor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CorredorController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Corredor::all()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:corredors',
        ], [
            'name.required' => 'El nombre del corredor es obligatorio',
            'name.unique' => 'Este corredor ya existe',
        ]);

        if ($validator->fails()) {
            return response()->json(["message" => "Error de validación", "errors" => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();
            $corredor = Corredor::create($validator->validated());
            DB::commit();

            return response()->json(['message' => 'Corredor creado con éxito', 'data' => $corredor], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(["message" => "Error al crear el corredor", "error" => $e->getMessage()], 500);
        }
    }
    
    // ... (Los métodos 'update' y 'destroy' son idénticos a los de Client/Provider)
}