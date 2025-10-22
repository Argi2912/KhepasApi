<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Currency;

class CurrenciesController extends Controller
{
    public function index(){
        $currencies = Currency::all();

        if (!$currencies) {
            return response()->json([
                "message" => "Divisas no encontradas"
            ], 404);
        }

        return response()->json([
            "message" => "Divisas encontradas",
            "data" => $currencies
        ], 200);
    }

    public function store(Request $request){
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:30',
        ], [
            'name.required' => 'El nombre de la divisa es obligatorio',
            'name.string' => 'El nombre de la divisa debe ser una cadena de caracteres',
            'name.max' => 'El nombre de la divisa debe tener un máximo de 30 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        try {
            DB::beginTransaction();
            
            $currency = Currency::create($data);

            return response()->json([
                'message' => 'Divisa creada con exito',
                'data' => $currency
            ], 201);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al crear la divisa",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id){
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:30',
        ], [
            'name.required' => 'El nombre de la divisa es obligatorio',
            'name.string' => 'El nombre de la divisa debe ser una cadena de caracteres',
            'name.max' => 'El nombre de la divisa debe tener un máximo de 30 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }
        try {
            DB::beginTransaction();

            $currency = Currency::findOrFail($id);
            $currency->update($data);

            return response()->json([
                "message" => "Divisa actualizada con exito",
                "data" => $currency
            ], 200);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al actualizar la divisa",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id){
        $currency = Currency::findOrFail($id);
        $currency->delete();

        return response()->json([
            "message" => "Divisa eliminada con exito",
            "data" => $currency
        ], 200);
    }
}
