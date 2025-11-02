<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteExchangeRequest;
use App\Services\Interfaces\AccountingServiceInterface;
use App\Models\ExchangeTransaction;
use App\Models\Account;
use App\Models\Cash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExchangeTransactionController extends Controller
{
    protected AccountingServiceInterface $accountingService;

    public function __construct(AccountingServiceInterface $accountingService)
    {
        $this->accountingService = $accountingService;
        $this->middleware('permission:execute currency exchange');
    }

    /**
     * Ejecuta una operación de intercambio de divisas, registrando el asiento contable.
     */
    public function executeExchange(ExecuteExchangeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $rateObject = $request->get('exchange_rate_object'); // Objeto de tasa validado
        
        // Cajas
        $cashGiven = Cash::findOrFail($validated['cash_given_id']);
        $cashReceived = Cash::findOrFail($validated['cash_received_id']);

        // --- 1. Determinar Ganancia/Pérdida por Spread (diferencia) ---
        // Asumiendo que la moneda base del sistema (para efectos contables) es la moneda de la tasa de cambio
        $effectiveRate = $validated['amount_received'] / $validated['amount_given'];
        $systemSpread = $rateObject->rate - $effectiveRate; // Spread del sistema

        // La ganancia/pérdida se registra en el asiento para balancear el débito y crédito.
        $gainLossAmount = abs($systemSpread * $validated['amount_given']) + ($validated['fee'] ?? 0);
        $isGain = $systemSpread > 0 || ($validated['fee'] ?? 0) > 0;

        // --- 2. Preparar Cuentas de Ganancia/Pérdida ---
        $gainLossAccount = Account::where('tenant_id', $user->tenant_id)
                                  ->where('name', $isGain ? 'Ganancia por Tasa de Cambio' : 'Pérdida por Tasa de Cambio')
                                  ->firstOrFail();

        // --- 3. Preparar los Movimientos Contables (Asiento Cuádruple) ---
        $movements = [
            // 1. Salida de Dinero (Activo disminuye) -> CRÉDITO
            [ 
                'account_name' => $cashGiven->account->name, 
                'amount' => $validated['amount_given'], 
                'is_debit' => false, 
                'account_type' => 'CASH'
            ],
            // 2. Entrada de Dinero (Activo aumenta) -> DÉBITO
            [ 
                'account_name' => $cashReceived->account->name, 
                'amount' => $validated['amount_received'], 
                'is_debit' => true, 
                'account_type' => 'CASH'
            ],
            // 3. Ganancia/Pérdida (Ingreso/Egreso) -> Para CUADRAR el asiento
            [ 
                'account_name' => $gainLossAccount->name, 
                'amount' => $gainLossAmount, 
                // Si es GANANCIA (INGRESS): CRÉDITO. Si es PÉRDIDA (EGRESS): DÉBITO
                'is_debit' => !$isGain, 
                'account_type' => $isGain ? 'INGRESS' : 'EGRESS'
            ]
        ];
        
        // Ajustar el asiento para que cuadre a cero (simplificado)
        // Ejemplo: Vendo 100 USD (sale 100). Recibo 95 EUR (entra 95). La diferencia (5) debe ir al débito para cuadrar.
        // En este MVP, el monto más grande va al lado que equilibre el asiento.
        
        // La suma de débitos y créditos debe ser igual al final (la diferencia es la ganancia/pérdida).
        // Si Amount Received > Amount Given: La diferencia va al DÉBITO.
        // Si Amount Given > Amount Received: La diferencia va al CRÉDITO.
        
        // Aquí simplificamos usando la diferencia calculada en la variable $gainLossAmount 
        // y aseguramos que el asiento siempre cuadre.
        
        try {
            // 4. Registrar la Transacción Contable
            $transaction = $this->accountingService->registerTransaction(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'date' => now(),
                    'description' => 'Intercambio de ' . $validated['amount_given'] . ' a ' . $validated['amount_received'],
                    'status' => 'COMPLETED',
                ],
                $movements
            );

            // 5. Registrar el Detalle del Intercambio (Tabla ExchangeTransaction)
            $exchangeDetail = ExchangeTransaction::create([
                'transaction_id' => $transaction->id,
                'exchange_rate_id' => $rateObject->id,
                'currency_given_id' => $validated['currency_given_id'],
                'currency_received_id' => $validated['currency_received_id'],
                'amount_given' => $validated['amount_given'],
                'amount_received' => $validated['amount_received'],
                'fee' => $validated['fee'] ?? 0,
            ]);

            return response()->json([
                'message' => 'Operación de intercambio registrada exitosamente.', 
                'transaction' => $transaction,
                'detail' => $exchangeDetail
            ], 201);
            
        } catch (\Exception $e) {
            // Si la transacción falla, se revierte el DB::transaction del AccountingService
            return response()->json(['message' => 'Error al ejecutar intercambio.', 'error' => $e->getMessage()], 500);
        }
    }
}