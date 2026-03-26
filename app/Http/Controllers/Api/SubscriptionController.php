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
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->baseUrl = config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function createPayPalOrder(Request $request)
    {
        $request->validate([
            'plan' => 'required|string',
            'amount' => 'required|numeric'
        ]);
        $accessToken = $this->getAccessToken();
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'description' => "Plan " . strtoupper($request->plan),
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($request->amount, 2, '.', '')
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
        $request->validate(['orderID' => 'required|string']);
        $accessToken = $this->getAccessToken();
        $orderId = $request->orderID;
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");
        if ($response->successful() && $response->json('status') === 'COMPLETED') {
            // LÓGICA DE NEGOCIO:
            // 1. Obtener el tenant (usuario autenticado)
            // 2. Activar suscripción y actualizar fecha de vencimiento
            $user = $request->user();
            $tenant = $user->tenant;

            $tenant->update([
                'is_active' => true,
                'last_payment_at' => now(),
                'expires_at' => now()->addMonth(), // O según el plan
                'paypal_order_id' => $orderId
            ]);
            return response()->json(['status' => 'success', 'message' => 'Suscripción activada']);
        }
        return response()->json(['status' => 'error', 'message' => 'No se pudo completar el pago'], 400);
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
