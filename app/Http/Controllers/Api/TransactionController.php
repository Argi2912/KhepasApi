<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Investor;
use App\Services\TransactionService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected $service;

    public function __construct(TransactionService $service)
    {
        $this->service = $service;
    }

    // ... otros métodos ...

    // ✅ MÉTODO NUEVO PARA RECARGAS
    public function addBalance(Request $request)
    {
        // Validación simplificada (no pide cuenta bancaria)
        $request->validate([
            'entity_type' => 'required|string',
            'entity_id'   => 'required|integer',
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'nullable|string'
        ]);

        // Detectar si es Proveedor o Inversionista
        $modelClass = $request->entity_type; // Ej: "App\Models\Provider"
        
        // Pequeño ajuste por si el frontend manda solo "providers"
        if ($request->entity_type === 'providers') $modelClass = Provider::class;
        if ($request->entity_type === 'investors') $modelClass = Investor::class;

        $entity = $modelClass::findOrFail($request->entity_id);

        // Llamamos a tu servicio corregido
        $this->service->addBalanceToEntity(
            $entity,
            $request->amount,
            'USD', // O la moneda que manejes
            $request->description
        );

        return response()->json(['message' => 'Recarga exitosa']);
    }
}