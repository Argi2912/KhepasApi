<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'currency_code' => 'nullable|string|max:5',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $query = Account::query();

        $query->when($request->currency_code, function ($q, $code) {
            return $q->currencyCode($code);
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'currency_code' => 'required|string|max:5',
            'balance' => 'required|numeric|min:0',
            'type' => 'nullable|string',    // Agregado para soportar tipo de cuenta
            'details' => 'nullable|string', // Agregado para soportar detalles
        ]);

        $account = Account::create($validated);

        return response()->json($account, 201);
    }

    public function show(Account $account)
    {
        return $account;
    }

    /**
     * Actualiza la cuenta.
     * 🛡️ BLINDAJE: Se han eliminado 'balance' y 'currency_code' de la validación.
     * Esto impide que se modifique el dinero o la moneda al editar el nombre.
     */
    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255',
            'type'    => 'nullable|string',
            'details' => 'nullable|string',
            // 🚨 IMPORTANTE: No incluimos 'balance' ni 'currency_code' aquí.
            // Si el frontend los envía, Laravel los ignorará porque no están validados.
        ]);

        $account->update($validated);

        return response()->json($account);
    }

    public function destroy(Account $account)
    {
        // 🛡️ Seguridad: No permitir borrar cuentas con dinero
        if ($account->balance > 0 || $account->balance < 0) {
             return response()->json(['message' => 'No se puede eliminar una cuenta que tiene saldo (positivo o negativo).'], 400);
        }

        $account->delete();
        return response()->noContent();
    }
}