<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class ExchangeRateController extends Controller
{
    public function index(){
        $exchangeRates = ExchangeRate::all();

        if (!$exchangeRates) {
            return response()->json([
                'message' => 'No se encontraron tasas de cambio',
            ], 404);
        }

        return response()->json([
            'exchangeRates' => $exchangeRates,
            'message' => 'Tasas de cambio obtenidas exitosamente',
        ], 200);
    }

    public function store(Request $request){
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'value' => 'required|numeric',
        ], [
            'name.required' => 'El nombre es requerido',
            'name.string' => 'El nombre debe ser un string',
            'name.max' => 'El nombre debe tener maximo 255 caracteres',
            'value.required' => 'El valor es requerido',
            'value.numeric' => 'El valor debe ser un numero',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 400);
        }

        try {
            DB::beginTransaction();

            $exchangeRate = ExchangeRate::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Tasa de cambio creada exitosamente',
                'exchangeRate' => $exchangeRate,
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear la tasa de cambio',
            ], 500);
        }
    }

    public function update(Request $request, $id){
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'value' => 'required|numeric',
        ], 
        
        [
            'name.required' => 'El nombre es requerido',
            'name.string' => 'El nombre debe ser un string',
            'name.max' => 'El nombre debe tener maximo 255 caracteres',
            'value.required' => 'El valor es requerido',
            'value.numeric' => 'El valor debe ser un numero',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 400);
        }

        try {
            DB::beginTransaction();

            $exchangeRate = ExchangeRate::find($id);

            if (!$exchangeRate) {
                return response()->json([
                    'message' => 'No se encontro la tasa de cambio',
                ], 404);
            }

            $exchangeRate->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Tasa de cambio actualizada exitosamente',
                'exchangeRate' => $exchangeRate,
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al actualizar la tasa de cambio',
            ], 500);
        }
    }

    public function destroy($id){
        try {
            DB::beginTransaction();

            $exchangeRate = ExchangeRate::find($id);

            if (!$exchangeRate) {
                return response()->json([
                    'message' => 'No se encontro la tasa de cambio',
                ], 404);
            }

            $exchangeRate->delete();

            DB::commit();

            return response()->json([
                'message' => 'Tasa de cambio eliminada exitosamente',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al eliminar la tasa de cambio',
            ], 500);
        }
    }
}
