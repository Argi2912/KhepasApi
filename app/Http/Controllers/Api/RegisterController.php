<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    protected $apiKey;
    protected $apiSecret;
    protected $baseUrl;
    protected $planPriceUsd = 1.00; // Precio bajo para pruebas (ajusta cuando estés en producción)

    public function __construct()
    {
        $config = config('services.binance_pay');
        $this->apiKey    = $config['key'];
        $this->apiSecret = $config['secret'];

        // Cambia a 'test' en .env para usar sandbox
        $this->baseUrl = $config['env'] === 'test'
            ? 'https://testnet.bapi.binance.com'
            : 'https://bapi.binance.com';
    }

    private function generateSignature(string $timestamp, string $nonce, string $body): string
    {
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return strtoupper(hash_hmac('sha512', $payload, $this->apiSecret));
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'company_name'     => 'required|string|max:255|unique:tenants,name',
            'admin_name'       => 'required|string|max:255',
            'admin_email'      => 'required|email|max:255|unique:users,email',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                // 1. Crear Tenant inactivo
                $tenant = Tenant::create([
                    'name'      => $validated['company_name'],
                    'is_active' => false,
                ]);

                // 2. Crear Admin
                $admin = User::create([
                    'tenant_id' => $tenant->id,
                    'name'      => $validated['admin_name'],
                    'email'     => $validated['admin_email'],
                    'password'  => Hash::make($validated['password']),
                ]);
                $admin->assignRole('admin_tenant');

                // 3. Preparar orden Binance Pay
                $merchantTradeNo = 'REG-' . $tenant->id . '-' . Str::random(12);

                $timestamp = (string) (round(microtime(true) * 1000));
                $nonce     = Str::random(32);

                $bodyArray = [
                    'merchantTradeNo' => $merchantTradeNo,
                    'tradeType'       => 'WEB', // ¡¡REQUERIDO!! Para pagos desde navegador
                    'productName'     => 'Plan Básico - ' . $validated['company_name'],
                    'productDetail'   => 'Acceso completo al sistema TuConpay',
                    'totalFee'        => number_format($this->planPriceUsd, 2, '.', ''),
                    'currency'        => 'USDT',
                    'goodsType'       => '01',
                    'goodsCategory'   => '0000',
                    // Opcional: URLs de retorno (puedes crear vistas simples success/cancel)
                    // 'returnUrl'       => url('/payment/success'),
                    // 'cancelUrl'       => url('/payment/cancel'),
                ];

                $body = json_encode($bodyArray);

                $signature = $this->generateSignature($timestamp, $nonce, $body);

                // 4. Request a Binance Pay (v2 endpoint)
                $response = Http::withHeaders([
                    'BinancePay-Timestamp'       => $timestamp,
                    'BinancePay-Nonce'           => $nonce,
                    'BinancePay-Certificate-SN'  => $this->apiKey,
                    'BinancePay-Signature'       => $signature,
                    'Content-Type'               => 'application/json',
                ])->post($this->baseUrl . '/binancepay/openapi/v2/order', $bodyArray);

                // Mejor manejo de errores
                if ($response->failed()) {
                    throw new \Exception('Error de conexión con Binance Pay: ' . $response->status());
                }

                $respJson = $response->json();

                Log::info('Respuesta Completa de Binance:', $respJson);

                if ($response->failed() || ($respJson['status'] ?? null) !== 'SUCCESS') {
                    // Extraemos el detalle del error que manda Binance
                    $binanceCode = $respJson['code'] ?? 'N/A';
                    $binanceMsg  = $respJson['errorMessage'] ?? 'Error sin mensaje detallado';

                    // Si Binance manda errores de validación específicos, suelen venir en 'data'
                    $extraData = isset($respJson['data']) ? json_encode($respJson['data']) : '';

                    throw new \Exception("Binance dice ($binanceCode): $binanceMsg $extraData");
                }

                if (($respJson['status'] ?? null) !== 'SUCCESS') {
                    $errorMsg = $respJson['errorMessage'] ?? 'Error desconocido';
                    $errorCode = $respJson['code'] ?? 'N/A';
                    throw new \Exception("Binance Pay error ({$errorCode}): {$errorMsg}");
                }

                $orderData = $respJson['data'];

                // 5. Guardar en tenant
                $tenant->update([
                    'binance_merchant_trade_no' => $merchantTradeNo,
                    'binance_prepay_id'         => $orderData['prepayId'] ?? null,
                ]);

                return [
                    'tenant'   => $tenant,
                    'admin'    => $admin,
                    'payment'  => $orderData,
                ];
            });

            // Ajustamos los nombres de campos según la respuesta REAL de Binance Pay
            return response()->json([
                'message' => 'Registro exitoso. Completa el pago para activar tu cuenta.',
                'data'    => [
                    'tenant_id'    => $result['tenant']->id,
                    'company_name' => $result['tenant']->name,
                    'admin_email'  => $result['admin']->email,
                    'payment'      => [
                        // URL directa a la imagen QR (perfecta para <img>)
                        'qr_code_url'  => $result['payment']['qrcodeLink'] ?? null,
                        // URL del checkout de Binance (redirección directa)
                        'checkout_url' => $result['payment']['checkoutUrl'] ?? null,
                        // Deep link para app Binance (opcional)
                        'deeplink'     => $result['payment']['deeplink'] ?? null,
                        // Universal URL (funciona en web y app)
                        'universal_url' => $result['payment']['universalUrl'] ?? null,
                        'prepay_id'    => $result['payment']['prepayId'] ?? null,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            // Loguea el error completo para debug (en production quita el detalle)
            Log::error('Registro Binance Pay fallido: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error en el registro o creación de orden de pago',
                'error'   => $e->getMessage(), // Quita esto en producción
            ], 500);
        }
    }

    public function binanceWebhook(Request $request)
    {
        $timestamp = $request->header('BinancePay-Timestamp');
        $nonce     = $request->header('BinancePay-Nonce');
        $signature = $request->header('BinancePay-Signature');
        $body      = $request->getContent();

        if (!$timestamp || !$nonce || !$signature) {
            return response('Missing headers', 400);
        }

        $expectedSignature = $this->generateSignature($timestamp, $nonce, $body);

        if (!hash_equals($expectedSignature, $signature)) {
            return response('Invalid signature', 400);
        }

        $payload = $request->json()->all();

        // Binance envía varios eventos, solo nos interesa el pago exitoso
        if (($payload['bizStatus'] ?? null) === 'PAY_SUCCESS') {
            $merchantTradeNo = $payload['merchantTradeNo'] ?? null;

            if ($merchantTradeNo) {
                $tenant = Tenant::where('binance_merchant_trade_no', $merchantTradeNo)->first();

                if ($tenant && !$tenant->is_active) {
                    $tenant->update(['is_active' => true]);

                    // Opcional: enviar email de bienvenida
                    // Mail::to($tenant->admin->email)->send(new WelcomeMail($tenant));
                }
            }
        }

        return response('OK', 200);
    }
}
