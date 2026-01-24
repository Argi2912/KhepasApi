<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalTransaction;
use App\Models\Account;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DailyClosingController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        
        // 1. Definir la fecha
        $date = $request->input('date', Carbon::now()->format('Y-m-d'));

        // 2. Traer TODAS las transacciones del día
        // Cargamos 'account' y 'entity' para tener los nombres reales
        $transactions = InternalTransaction::with(['account', 'entity'])
            ->where('tenant_id', $tenantId)
            ->whereDate('transaction_date', $date)
            ->get();

        // -------------------------------------------------------------
        // A. RESUMEN GLOBAL POR MONEDA
        // -------------------------------------------------------------
        $globalSummary = $transactions->groupBy(function($tx) {
            return $tx->account->currency_code ?? 'USD';
        })->map(function ($txs, $currency) {
            $income = $txs->where('type', 'income')->sum('amount');
            $expense = $txs->where('type', 'expense')->sum('amount');
            return [
                'currency' => $currency,
                'total_income' => $income,
                'total_expense' => $expense,
                'net_balance' => $income - $expense
            ];
        })->values();

        // -------------------------------------------------------------
        // B. DETALLE POR CUENTA
        // -------------------------------------------------------------
        $allAccounts = Account::where('tenant_id', $tenantId)->get();

        $accountsDetails = $allAccounts->map(function ($account) use ($transactions) {
            $accountMoves = $transactions->where('account_id', $account->id);
            $in = $accountMoves->where('type', 'income')->sum('amount');
            $out = $accountMoves->where('type', 'expense')->sum('amount');

            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'currency' => $account->currency_code,
                'income' => $in,
                'expense' => $out,
                'net_flow' => $in - $out,
                'final_balance' => $account->balance
            ];
        });

        // -------------------------------------------------------------
        // C. DETALLE DE OPERACIONES (Log del día)
        // -------------------------------------------------------------
        $logs = $transactions->sortByDesc('created_at')->take(100)->map(function($tx) {
                
            $accountName = 'Cuenta Eliminada'; // Valor por defecto

            // CASO 1: CUENTA BANCARIA REAL
            if ($tx->account) {
                $accountName = $tx->account->name;
            } 
            
            // CASO 2: BILLETERA DE PROVEEDOR
            // 🔥 FIX: Verificamos ($tx->entity_type) antes de usar str_contains para evitar error por NULL
            elseif ($tx->entity_type && str_contains($tx->entity_type, 'Provider')) {
                // Si existe la entidad cargada, usamos su nombre real
                $realName = $tx->entity ? $tx->entity->name : ($tx->person_name ?? 'Proveedor');
                $accountName = "Billetera: $realName";
            }
            
            // CASO 3: CAPITAL DE INVERSIONISTA
            elseif ($tx->entity_type && str_contains($tx->entity_type, 'Investor')) {
                $realName = $tx->entity ? $tx->entity->name : ($tx->person_name ?? 'Inversionista');
                $accountName = "Capital: $realName";
            }

            // CASO 4: OTROS (Fallback)
            elseif ($tx->person_name) {
                    $accountName = "Virtual: " . $tx->person_name;
            }

            return [
                'id' => $tx->id,
                'time' => Carbon::parse($tx->created_at)->format('H:i'),
                'type' => $tx->type, 
                'category' => $tx->category,
                'description' => $tx->description,
                'amount' => $tx->amount,
                'account' => $accountName, // <--- Aquí ya sale el nombre correcto
                'person' => $tx->person_name ?? 'Sistema',
            ];
        })->values(); // Re-indexar array para evitar problemas en JSON

        // 🔥 ESTO FALTABA: Retornar los datos al frontend
        return response()->json([
            'date' => $date,
            'global_summary' => $globalSummary,
            'accounts_details' => $accountsDetails,
            'movements' => $logs
        ]);
    }
}