<?php

namespace App\Http\Controllers;

use App\Models\Solicitud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SolicitudController extends Controller
{
    /**
     * Muestra una lista de las solicitudes.
     */
    public function index()
    {
        // Cargar solicitudes con sus relaciones
        $solicitudes = Solicitud::with('requestType', 'client', 'provider', 'corredor', 'admin')
            ->orderBy('id', 'desc')
            ->get();
            
        return response()->json(['data' => $solicitudes]);
    }

    /**
     * Guarda una nueva solicitud.
     */
    public function store(Request $request)
    {
        // 1. Validación de todos los campos del wizard
        $validator = Validator::make($request->all(), [
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0',
            'comisionCobrada' => 'required|numeric|min:0',
            'comisionProveedor' => 'required|numeric|min:0',
            'comisionAdmin' => 'required|numeric|min:0',
            'origen' => 'required|string|max:255',
            'destino' => 'required|string|max:255',
            'numero' => 'required|string|max:100|unique:solicituds',
            
            // Validación de los IDs
            'request_type_id' => 'required|integer|exists:request_types,id',
            'client_id' => 'required|integer|exists:clients,id',
            'provider_id' => 'required|integer|exists:providers,id',
            'corredor_id' => 'required|integer|exists:corredors,id',
            'admin_id' => 'required|integer|exists:users,id', // Asumiendo que 'admin' es un 'User'
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Error de validación",
                "errors" => $validator->errors()
            ], 400);
        }

        // 2. Creación del registro
        try {
            DB::beginTransaction();
            
            $solicitud = Solicitud::create($validator->validated());

            DB::commit();

            return response()->json([
                'message' => 'Solicitud registrada con éxito',
                'data' => $solicitud
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "Error al registrar la solicitud",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}