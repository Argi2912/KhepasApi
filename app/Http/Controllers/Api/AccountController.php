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
            // CAMBIO: De 'size:3' a 'max:5' para que el filtro sea flexible
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
            // ESTE YA ESTABA BIEN: max:5 permite de 1 a 5 caracteres
            'currency_code' => 'required|string|max:5', 
            'balance' => 'required|numeric|min:0',
        ]);

        $account = Account::create($validated);

        return response()->json($account, 201);
    }

    public function show(Account $account)
    {
        return $account;
    }

    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            // CAMBIO: Quitamos 'size:3' y ponemos 'max:5'
            'currency_code' => 'sometimes|required|string|max:5', 
            'balance' => 'sometimes|required|numeric|min:0',
        ]);

        $account->update($validated);

        return response()->json($account);
    }

    public function destroy(Account $account)
    {
        $account->delete();
        return response()->noContent();
    }
}