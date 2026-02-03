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
    protected $planPriceUsd = 1.00;

    public function __construct()
    {
        $config = config('services.binance_pay');
        $this->apiKey    = $config['key'];
        $this->apiSecret = $config['secret'];

        $this->baseUrl = $config['env'] === 'test'
            ? 'https://testnet.bapi.binance.com'
            : 'https://bapi.binance.com';
    }

    /**
     * Genera la firma SHA512 requerida por Binance.
     */
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

                // 3. Preparar datos para Binance Pay
                $merchantTradeNo = 'REG-' . $tenant->id . '-' . Str::random(12);
                $timestamp = (string) (round(microtime(true) * 1000));
                $nonce     = Str::random(32);

                $bodyArray = [
                    'merchantTradeNo' => $merchantTradeNo,
                    'tradeType'       => 'WEB',
                    'productName'     => 'Plan Básico - ' . $validated['company_name'],
                    'productDetail'   => 'Acceso completo al sistema TuConpay',
                    'totalFee'        => number_format($this->planPriceUsd, 2, '.', ''),
                    'currency'        => 'USDT',
                    'goodsType'       => '01',
                    'goodsCategory'   => '0000',
                    'terminalType'    => 'WEB' // Recomendado para evitar errores de validación
                ];

                // IMPORTANTE: Usar flags para que el JSON sea idéntico en firma y envío
                $body = json_encode($bodyArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $signature = $this->generateSignature($timestamp, $nonce, $body);

                // 4. Request a Binance Pay usando withBody para enviar el string exacto
                $response = Http::withoutVerifying()
                    ->withHeaders([
                        'BinancePay-Timestamp'      => $timestamp,
                        'BinancePay-Nonce'          => $nonce,
                        'BinancePay-Certificate-SN' => $this->apiKey,
                        'BinancePay-Signature'      => $signature,
                        'Content-Type'              => 'application/json',
                    ])
                    ->withBody($body, 'application/json')
                    ->post($this->baseUrl . '/binancepay/openapi/v2/order');

                $respJson = $response->json();

                // Log para auditoría técnica
                Log::info('Respuesta Binance Pay:', ['body' => $response->body()]);

                if (!$response->successful() || ($respJson['status'] ?? null) !== 'SUCCESS') {
                    $binanceCode = $respJson['code'] ?? 'S/N';
                    $binanceMsg  = $respJson['errorMessage'] ?? 'Respuesta vacía o error de red';
                    throw new \Exception("Binance dice ($binanceCode): $binanceMsg");
                }

                $orderData = $respJson['data'];

                // 5. Guardar datos de pago en el tenant
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

            return response()->json([
                'message' => 'Registro exitoso. Completa el pago para activar tu cuenta.',
                'data'    => [
                    'tenant_id'    => $result['tenant']->id,
                    'company_name' => $result['tenant']->name,
                    'admin_email'  => $result['admin']->email,
                    'payment'      => [
                        'qr_code_url'   => $result['payment']['qrcodeLink'] ?? null,
                        'checkout_url'  => $result['payment']['checkoutUrl'] ?? null,
                        'deeplink'      => $result['payment']['deeplink'] ?? null,
                        'universal_url' => $result['payment']['universalUrl'] ?? null,
                        'prepay_id'     => $result['payment']['prepayId'] ?? null,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registro Binance Pay fallido: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error en el registro o creación de orden de pago',
                'error'   => $e->getMessage(),
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
            Log::warning('Webhook: Firma inválida detectada.');
            return response('Invalid signature', 400);
        }

        $payload = json_decode($body, true);

        if (($payload['bizStatus'] ?? null) === 'PAY_SUCCESS') {
            // En V2, los datos suelen venir dentro de una llave 'data' o directamente
            $data = $payload['data'] ?? $payload;
            $merchantTradeNo = $data['merchantTradeNo'] ?? null;

            if ($merchantTradeNo) {
                $tenant = Tenant::where('binance_merchant_trade_no', $merchantTradeNo)->first();

                if ($tenant && !$tenant->is_active) {
                    $tenant->update(['is_active' => true]);
                    Log::info("Tenant {$tenant->id} activado por pago exitoso.");
                }
            }
        }

        return response()->json(['returnCode' => 'SUCCESS', 'returnMessage' => null], 200);
    }
}
