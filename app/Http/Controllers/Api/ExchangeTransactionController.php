<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteExchangeRequest; 
use App\Models\ExchangeTransaction;
use App\Models\Account;
use App\Models\Cash;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\ExchangeRate;
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

    private function getAccountId(string $accountName, int $tenantId): int
    {
        $account = Account::where('name', $accountName)
                          ->where('tenant_id', $tenantId)
                          ->first();
    
        if (!$account) {
            // Esto lanzará la excepción que ahora SÍ será capturada
            throw new \Exception("Error contable: La cuenta maestra '$accountName' no se encuentra registrada.", 422);
        }
        
        return $account->id;
    }

    public function executeExchange(ExecuteExchangeRequest $request): JsonResponse
    {
        // --- INICIO DE LA CORRECCIÓN: 'try' envuelve TODO el método ---
        try {
            $validated = $request->validated();
            $user = Auth::user();
            $tenantId = $user->tenant_id;

            // Objetos cargados eficientemente en el Request
            $cashGiven = $request->get('cash_given_object');
            $cashReceived = $request->get('cash_received_object');
            $rateObject = $request->get('exchange_rate_object');

            // IDs de Cuentas Contables (maestras)
            $cashAccountId = $cashGiven->account->id;
            
            // Estas llamadas ahora están dentro del try...catch
            $providerCommAccountId = $this->getAccountId('Comisiones de Proveedores (Costo)', $tenantId);
            $platformCommAccountId = $this->getAccountId('Comisiones de Plataforma (Costo)', $tenantId);
            $companyCommAccountId = $this->getAccountId('Ingresos por Comisiones (KHEPAS)', $tenantId); // CORREGIDO: Nombre coincide con Seeder

            // Montos (ya calculados y validados en el Request)
            $amountGiven = $validated['amount_given'];
            $amountReceived = $request->get('amount_received'); // El neto * tasa
            $providerAmount = $request->get('provider_amount');
            $platformAmount = $request->get('platform_amount');
            $companyAmount = $request->get('company_amount');

            // Usamos DB::transaction para asegurar atomicidad
            return DB::transaction(function () use (
                $validated, $user, $tenantId, $cashGiven, $cashReceived, $rateObject, $cashAccountId,
                $providerCommAccountId, $platformCommAccountId, $companyCommAccountId,
                $amountGiven, $amountReceived, $providerAmount, $platformAmount, $companyAmount
            ) {

                // 1. Crear la Transacción (Asiento General)
                $transaction = Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'date' => $validated['date'],
                    'description' => $validated['description'] ?? 'Intercambio de divisa',
                    'reference_code' => 'EXC-' . Str::random(8),
                    'status' => 'COMPLETED',
                ]);

                // 2. Asiento Contable (Doble Partida)
                
                // 2a. CRÉDITO (Salida) de la Caja Maestra
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cashAccountId,
                    'amount' => $amountGiven, // Sale el total
                    'is_debit' => false,
                ]);

                // 2b. DÉBITO (Entrada) a la Caja Maestra
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $cashAccountId,
                    'amount' => $amountReceived, // Entra el neto convertido
                    'is_debit' => true,
                ]);

                // 2c. DÉBITOS (Costos) de Comisiones
                if ($providerAmount > 0) {
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $providerCommAccountId,
                        'amount' => $providerAmount,
                        'is_debit' => true,
                    ]);
                }
                if ($platformAmount > 0) {
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $platformCommAccountId,
                        'amount' => $platformAmount,
                        'is_debit' => true,
                    ]);
                }

                // 2d. CRÉDITO (Ingreso) de Comisión KHEPAS
                if ($companyAmount > 0) {
                     TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $companyCommAccountId,
                        'amount' => $companyAmount,
                        'is_debit' => false, // Ingreso aumenta por el Crédito
                    ]);
                }

                // 3. ACTUALIZACIÓN CRUCIAL DE BALANCES DE CAJA
                $cashGiven->balance -= $amountGiven;
                $cashReceived->balance += $amountReceived;
                $cashGiven->save();
                $cashReceived->save();

                // 4. Registrar el Detalle del Intercambio (Trazabilidad)
                $exchangeDetail = ExchangeTransaction::create([
                    'transaction_id' => $transaction->id,
                    'exchange_rate_id' => $rateObject->id,
                    'customer_user_id' => $validated['customer_user_id'],
                    'provider_user_id' => $validated['provider_user_id'] ?? null,
                    'broker_user_id' => $validated['broker_user_id'],
                    
                    'currency_given_id'    => $request->get('currency_given_id'),
                    'currency_received_id' => $request->get('currency_received_id'),
                    
                    'amount_given'         => $amountGiven,
                    'net_amount_converted' => $request->get('net_amount_to_convert'),
                    'amount_received'      => $amountReceived,
                    'effective_rate'       => $request->get('effective_rate'),
                    
                    'commission_provider_percentage' => $validated['commission_provider_percentage'],
                    'commission_provider_amount'     => $request->get('provider_amount'),
                    
                    'commission_platform_percentage' => $validated['commission_platform_percentage'],
                    'commission_platform_amount'     => $request->get('platform_amount'),
                    
                    'commission_company_percentage'  => $validated['commission_company_percentage'],
                    'commission_company_amount'      => $request->get('company_amount'),
                    
                    'total_commission_expense_amount' => $request->get('total_expense_commissions'),
                ]);

                return response()->json([
                    'message' => 'Operación de intercambio registrada exitosamente.', 
                    'transaction' => $transaction->load('details'),
                    'detail' => $exchangeDetail
                ], 201);
            });

        // --- FIN DE LA CORRECCIÓN: 'catch' captura cualquier excepción previa ---
        } catch (\Exception $e) {
            $statusCode = $e->getCode() == 422 ? 422 : 500;
            return response()->json([
                'message' => $e->getMessage(), 
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }
}