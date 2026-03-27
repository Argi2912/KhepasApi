<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;

class SubscriptionController extends Controller
{
    private $baseUrl;
    private $clientId;
    private $clientSecret;
    public function __construct()
    {
        $mode = trim(config('services.paypal.mode', 'sandbox'));

        if ($mode === 'sandbox') {
            // Usa las credenciales exclusivas de Sandbox
            $this->clientId = config('paypal.sandbox.client_id');
            $this->clientSecret = config('paypal.sandbox.client_secret');
            $this->baseUrl = 'https://api-m.sandbox.paypal.com';
        } else {
            // Usa las credenciales de Producción
            $this->clientId = config('paypal.live.client_id');
            $this->clientSecret = config('paypal.live.client_secret');
            $this->baseUrl = 'https://api-m.paypal.com';
        }
    }

    public function createPayPalOrder(Request $request)
    {
        $request->validate([
            'plan' => 'required|string|in:normal,pro'
        ]);

        $amount = $request->plan === 'normal' ? 47.00 : 300.00;

        $accessToken = $this->getAccessToken();
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'description' => "Plan " . strtoupper($request->plan),
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($amount, 2, '.', '')
                    ]
                ]]
            ]);
        return response()->json($response->json(), $response->status());
    }
    /**
     * Paso 2: Capturar el pago y activar la suscripción
     */
    public function capturePayPalOrder(Request $request)
    {
        $request->validate([
            'orderID' => 'required|string',
            'plan' => 'required|string|in:normal,pro'
        ]);

        $accessToken = $this->getAccessToken();
        $orderId = $request->orderID;
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation' // Opcional: ayuda a traer más info si falla
            ])
            ->withBody('{}', 'application/json') // <--- SOLUCIÓN: Forzamos un objeto JSON vacío literal
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

        if ($response->successful() && $response->json('status') === 'COMPLETED') {
            $user = $request->user();
            $tenant = $user->tenant;

            $isPro = $request->plan === 'pro';
            $monthsToAdd = $isPro ? 13 : 1;

            $tenant->update([
                'is_active' => true,
                'plan_name' => $request->plan,
                'plan_price' => $isPro ? 300.00 : 47.00,
                'last_payment_at' => now(),
                'expires_at' => now()->addMonths($monthsToAdd),
                'paypal_order_id' => $orderId
            ]);

            return response()->json(['status' => 'success', 'message' => 'Suscripción activada']);
        }
        return response()->json([
            'status' => 'error',
            'message' => 'No se pudo completar el pago',
            'paypal_debug' => $response->json(), // Esto te dirá el error real (ej: ORDER_NOT_APPROVED)
            'http_code' => $response->status()
        ], 400);
    }

    /**
     * Activar el plan gratuito (1 mes) directamente sin PayPal
     */
    public function activateFreePlan(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        $tenant->update([
            'is_active' => true,
            'last_payment_at' => now(),
            'expires_at' => now()->addMonth(), // 1 mes para el plan gratuito
            'paypal_order_id' => 'free_' . uniqid()
        ]);

        return response()->json(['status' => 'success', 'message' => 'Plan gratuito activado']);
    }
    /**
     * Obtener Token de Acceso OAuth2
     */
    private function getAccessToken()
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post("{$this->baseUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials'
            ]);
        return $response->json('access_token');
    }
}
/**
 * 2. PAGO CON STRIPE (COMENTADO / FUTURO)
 */
    /*
    public function payWithStripe(Request $request)
    {
        $user = Auth::guard('api')->user();
        
        // Lógica de Stripe Checkout
        // \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        // $session = \Stripe\Checkout\Session::create([...]);

        return response()->json([
            'payment_url' => 'https://checkout.stripe.com/pay/cs_test_...',
            'session_id' => 'cs_test_123'
        ]);
    }
    */

/**
 * 3. PAGO CON BINANCE (COMENTADO / FUTURO)
 */
    /*
    public function payWithBinance(Request $request)
    {
        $user = Auth::guard('api')->user();

        // Lógica de Binance Pay
        // $response = Http::post('https://bpay.binanceapi.com/binancepay/openapi/v2/order', [...]);

        return response()->json([
            'payment_url' => 'https://pay.binance.com/checkout/123...',
            'prepay_id' => '123'
        ]);
    }
    */
