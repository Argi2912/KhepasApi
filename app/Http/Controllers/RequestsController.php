<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Exception;

class RequestsController extends Controller
{
    public function index(){
        $requests = Request::all();

        if (!$requests) {
            return response()->json([
                'message' => 'No se encontraron solicitudes',
            ], 404);
        }

        return response()->json([
            'requests' => $requests,
            'message' => 'Solicitudes obtenidas exitosamente',
        ], 200);
    }

    public function store(Request $request){
        $data = $request->all();

        $validator = Validator::make($data, [
            'amount' => 'required|numeric',
            'commission_charged' => 'required|numeric',
            'supplier_commission' => 'required|numeric',
            'admin_commission' => 'required|numeric',
            'client_id' => 'required|numeric',
            'broker_id' => 'required|numeric',
            'supplier_id' => 'required|numeric',
            'admin_id' => 'required|numeric',
            'source_platform_id' => 'required|numeric',
            'destination_platform_id' => 'required|numeric',
        ], [
            'amount.required' => 'El monto es requerido',
            'amount.numeric' => 'El monto debe ser un numero',
            'commission_charged.required' => 'El comision cobrado es requerido',
            'commission_charged.numeric' => 'El comision cobrado debe ser un numero',
            'supplier_commission.required' => 'La comision del proveedor es requerida',
            'supplier_commission.numeric' => 'La comision del proveedor debe ser un numero',
            'admin_commission.required' => 'La comision del administrador es requerida',
            'admin_commission.numeric' => 'La comision del administrador debe ser un numero',
            'client_id.required' => 'El id del cliente es requerido',
            'client_id.numeric' => 'El id del cliente debe ser un numero',
            'broker_id.required' => 'El id del broker es requerido',
            'broker_id.numeric' => 'El id del broker debe ser un numero',
            'supplier_id.required' => 'El id del proveedor es requerido',
            'supplier_id.numeric' => 'El id del proveedor debe ser un numero',
            'admin_id.required' => 'El id del administrador es requerido',
            'admin_id.numeric' => 'El id del administrador debe ser un numero',
            'source_platform_id.required' => 'El id de la plataforma de origen es requerido',
            'source_platform_id.numeric' => 'El id de la plataforma de origen debe ser un numero',
            'destination_platform_id.required' => 'El id de la plataforma de destino es requerido',
            'destination_platform_id.numeric' => 'El id de la plataforma de destino debe ser un numero',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 400);
        }

        try {
            DB::beginTransaction();

            $request = Request::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Solicitud creada exitosamente',
                'request' => $request,
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear la solicitud',
            ], 500);
        }
    }
}
