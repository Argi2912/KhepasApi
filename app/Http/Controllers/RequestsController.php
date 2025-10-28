<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Validator;

class RequestsController extends Controller
{
    public function index(){
        $requests = Request::with([
            'client:id,name', 
            'sourceCurrency:id,code', 
            'destinationCurrency:id,code',
            'requestType:id,name'
        ])->get();

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

    public function store(StoreRequestRequest $request){
        $validated = $request->validated();

        $validated['status'] = 'pending';

        $newRequest = Request::create($validated);

        return response()->json([
            'message' => 'Solicitud creada exitosamente',
            'request' => $newRequest,
        ], 201);
    }

    public function update(UpdateRequestRequest $request, $id){
        $validated = $request->validated();

        $request = Request::find($id);

        if (!$request) {
            return response()->json([
                'message' => 'Solicitud no encontrada',
            ], 404);
        }

        $request->update($validated);

        return response()->json([
            'message' => 'Solicitud actualizada exitosamente',
            'request' => $request,
        ], 200);

    }

    public function cancel($id){
        $request = Request::find($id);

        if (!$request) {
            return response()->json([
                'message' => 'Solicitud no encontrada',
            ], 404);
        }

        $request->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Solicitud cancelada exitosamente',
            'request' => $request,
        ], 200);
    }
}
