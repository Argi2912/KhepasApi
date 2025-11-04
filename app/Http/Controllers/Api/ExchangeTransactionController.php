<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteExchangeRequest;
use App\Models\ExchangeTransaction;
use App\Models\Account;
use App\Models\Cash;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExchangeTransactionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:execute currency exchange');
    }

    /**
     * Helper para obtener el ID de una cuenta por su nombre.
     */
    private function getAccountId(string $accountName, int $tenantId): int
    {
        $account = Account::where('name', $accountName)
                          ->where('tenant_id', $tenantId)
                          ->first();
    
        if (!$account) {
            throw new \Exception("Error contable: La cuenta maestra '$accountName' no se encuentra registrada para el inquilino.", 422);
        }
        
        return $account->id;
    }

    /**
     * Ejecuta una operación de intercambio de divisas, registrando el asiento contable.
     * MODIFICACIÓN: Desglosa los movimientos brutos para la cuenta de caja compartida.
     */
    public function executeExchange(ExecuteExchangeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        
        $rateObject = $request->input('exchange_rate_object'); 
        $currencyGivenId = $request->input('currency_given_id');
        $currencyReceivedId = $request->input('currency_received_id');

        if (!$rateObject) {
            return response()->json(['message' => 'Error interno: No se pudo determinar la tasa de cambio.'], 500);
        }
        
        $amountGiven = (float) $validated['amount_given'];
        $amountReceived = (float) $validated['amount_received'];
        $fee = (float) ($validated['fee'] ?? 0);
        $totalGivenCredit = $amountGiven + $fee; // Monto total que sale de la caja de origen

        try {
            return DB::transaction(function () use ($validated, $user, $tenantId, $rateObject, $currencyGivenId, $currencyReceivedId, $amountGiven, $amountReceived, $fee, $totalGivenCredit) {

                // 1. Bloquear Cajas y Validar Fondos
                $cashGiven = Cash::with('account')
                                 ->where('id', $validated['cash_given_id'])
                                 ->where('tenant_id', $tenantId)
                                 ->lockForUpdate()
                                 ->firstOrFail();
                
                $cashReceived = Cash::with('account')
                                    ->where('id', $validated['cash_received_id'])
                                    ->where('tenant_id', $tenantId)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                if ($cashGiven->balance < $totalGivenCredit) {
                    throw new \Exception('Fondos insuficientes en la caja de origen.', 422);
                }

                // 2. Lógica Contable
                $amountReceivedEquivalent = $amountReceived / $rateObject->rate;
                $gainLoss = $amountReceivedEquivalent - $amountGiven; 

                $cashGivenAccountId = $cashGiven->account_id;
                $cashReceivedAccountId = $cashReceived->account_id;
                $isCashAccountShared = ($cashGivenAccountId === $cashReceivedAccountId);
                
                // Nombres alineados con tu DB:
                $feeAccountId = $fee > 0 ? $this->getAccountId('Ingresos por Comisiones', $tenantId) : null;
                $gainLossAccountId = null;

                if ($gainLoss != 0) {
                    $accountName = $gainLoss > 0 ? 'Ganancia por Tasa de Cambio' : 'Pérdida por Tasa de Cambio';
                    $gainLossAccountId = $this->getAccountId($accountName, $tenantId);
                }
                
                // ... (Validaciones Defensivas se mantienen)


                // 3. Crear Encabezado de Transacción
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'] ?? now(),
                    'description' => $validated['description'] ?? 'Intercambio de divisas (Asiento desglosado/consolidado)',
                    'status' => 'COMPLETED',
                    'reference_code' => 'EXC-' . Str::random(6),
                ]);

                // 4. Acumular Detalles (Asientos)
                $accountMovements = [];

                $recordMovement = function ($accountId, $amount, $isDebit) use (&$accountMovements) {
                    if ($amount <= 0 || !$accountId) return; 

                    $amountCents = (int) round($amount * 100);
                    $sign = $isDebit ? 1 : -1;
                    
                    if (!isset($accountMovements[$accountId])) {
                        $accountMovements[$accountId] = 0;
                    }
                    
                    $accountMovements[$accountId] += $amountCents * $sign;
                };

                // A. MOVIMIENTOS DE CAJA (Se registrarán por separado si se comparten, sino se consolidan)
                $recordMovement($cashGivenAccountId, $totalGivenCredit, false); // CRÉDITO: Salida total
                $recordMovement($cashReceivedAccountId, $amountReceivedEquivalent, true); // DÉBITO: Entrada equivalente

                // B. GASTO POR COMISIÓN (DÉBITO)
                if ($fee > 0 && $feeAccountId) {
                    $recordMovement($feeAccountId, $fee, true);
                }

                // C. BALANCEAR ASIENTO (Ganancia o Pérdida)
                if ($gainLoss > 0) {
                    $recordMovement($gainLossAccountId, $gainLoss, false); // Ganancia: CRÉDITO
                } elseif ($gainLoss < 0) {
                    $recordMovement($gainLossAccountId, abs($gainLoss), true); // Pérdida: DÉBITO
                }

                // ==========================================================
                // 5. INSERCIÓN DE DETALLES: Separando Caja de otras cuentas
                // ==========================================================
                
                // Si la cuenta es compartida, insertamos los movimientos brutos de caja primero
                if ($isCashAccountShared) {
                     // 5.1. Desglose de Caja para ver el flujo bruto
                     
                     // DÉBITO: Entrada (equivalente) 
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $cashReceivedAccountId,
                        'amount' => $amountReceivedEquivalent, // Monto Bruto de Entrada (ej: 8.00)
                        'is_debit' => true,
                    ]);
                     // CRÉDITO: Salida (total)
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $cashGivenAccountId, 
                        'amount' => $totalGivenCredit, // Monto Bruto de Salida (ej: 8.12)
                        'is_debit' => false,
                    ]);
                    
                    // Eliminamos el ID de la caja del array de movimientos para que no se procese más.
                    unset($accountMovements[$cashGivenAccountId]);
                }

                // 5.2. Insertar el resto de los movimientos consolidados (Comisión, Ganancia/Pérdida)
                foreach ($accountMovements as $accountId => $netMovementCents) {
                    // Si el neto es cero (ej: si la comisión o gainLoss fueran 0), lo saltamos.
                    if ($netMovementCents === 0) continue; 

                    // Registramos el movimiento neto de las cuentas de Gasto/Ingreso.
                    $isDebit = $netMovementCents > 0;
                    $amount = abs($netMovementCents) / 100;

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $accountId,
                        'amount' => $amount,
                        'is_debit' => $isDebit,
                    ]);
                }

                // 6. ACTUALIZACIÓN CRUCIAL DE BALANCES DE CAJA (Movimiento real de divisas)
                $cashGiven->balance -= $totalGivenCredit;
                $cashReceived->balance += $amountReceived;
                $cashGiven->save();
                $cashReceived->save();

                // 7. Registrar el Detalle del Intercambio (Trazabilidad)
                $exchangeDetail = ExchangeTransaction::create([
                    'transaction_id' => $transaction->id,
                    'exchange_rate_id' => $rateObject->id,
                    'currency_given_id' => $currencyGivenId,
                    'currency_received_id' => $currencyReceivedId,
                    'amount_given' => $amountGiven,
                    'amount_received' => $amountReceived,
                    'fee' => $fee,
                ]);

                return response()->json([
                    'message' => 'Operación de intercambio y comisión registradas exitosamente.', 
                    'transaction' => $transaction->load('details'),
                    'detail' => $exchangeDetail
                ], 201);

            });
        } catch (\Exception $e) {
            $statusCode = $e->getCode() == 422 ? 422 : 500;
            return response()->json([
                'message' => $e->getMessage(), 
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }
}