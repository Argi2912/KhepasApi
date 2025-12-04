<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\Request;

class InvestorController extends Controller
{
    /**
     * Retorna lista de inversionistas activos para el selector.
     */
    public function index()
    {
        // Puedes agregar paginación si tienes muchos, pero para un select 'get' está bien.
        return Investor::where('is_active', true)
            ->latest()
            ->get(['id', 'name', 'alias']); 
    }

    /**
     * Crea un nuevo inversionista.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'alias' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        $investor = Investor::create($data);

        return response()->json($investor, 201);
    }
}