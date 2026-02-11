<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant; // <--- AGREGAR ESTA IMPORTACIÓN

class SubscriptionController extends Controller
{
    /**
     * MÉTODO 1: Pagar suscripción vencida (Login -> Pantalla Roja -> Pagar)
     * Este ya lo tenías y funcionaba bien.
     */
    public function payWithPaypal(Request $request)
    {
        $user = Auth::guard('api')->user();
        $tenant = $user->tenant;

        if (!$tenant->plan_price || $tenant->plan_price <= 0) {
            return response()->json(['error' => 'Error: Su empresa no tiene un precio de plan asignado.'], 400);
        }

        $amountValue = number_format($tenant->plan_price, 2, '.', '');
        $planName = $tenant->plan_name ?? 'Suscripción Mensual';

        $config = config('services.paypal');
        $clientId = $config['client_id'];
        $secret = $config['secret'];
        $mode = $config['mode'];
        $currency = $config['currency']; 

        $baseUrl = ($mode === 'sandbox') 
            ? 'https://api-m.sandbox.paypal.com' 
            : 'https://api-m.paypal.com';

        if (!$clientId || !$secret) {
            return response()->json(['error' => 'Error de configuración de PayPal.'], 500);
        }

        try {
            $authResponse = Http::withBasicAuth($clientId, $secret)
                ->asForm()
                ->post("$baseUrl/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($authResponse->failed()) {
                Log::error('PayPal Auth Error', $authResponse->json());
                return response()->json(['error' => 'No se pudo conectar con PayPal.'], 500);
            }

            $accessToken = $authResponse->json()['access_token'];

            $orderResponse = Http::withToken($accessToken)
                ->post("$baseUrl/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => "TENANT_{$tenant->id}",
                        'description' => "Pago de suscripción: {$planName}",
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => $amountValue
                        ]
                    ]],
                    'application_context' => [
                        'brand_name' => 'TuConpay',
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        // Si paga, vuelve a la pantalla de éxito
                        'return_url' => env('FRONTEND_URL', 'http://localhost:5173') . "/payment-success?tenant_id={$tenant->id}",
                        // Si cancela, vuelve a la pantalla de bloqueo
                        'cancel_url' => env('FRONTEND_URL', 'http://localhost:5173') . "/subscription-expired"
                    ]
                ]);

            if ($orderResponse->failed()) {
                Log::error('PayPal Order Error', $orderResponse->json());
                return response()->json(['error' => 'Error al generar la orden de pago.'], 500);
            }

            $approveLink = collect($orderResponse->json()['links'])->where('rel', 'approve')->first()['href'];

            return response()->json([
                'payment_url' => $approveLink,
                'order_id' => $orderResponse->json()['id']
            ]);

        } catch (\Exception $e) {
            Log::error('PayPal Exception: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno al procesar pago.'], 500);
        }
    }

    /**
     * MÉTODO 2 (NUEVO): Activar cuenta NUEVA tras el registro
     * Este es el que llama la vista "PaymentSuccess" cuando el usuario vuelve de PayPal.
     */
    public function captureRegistrationPayment(Request $request)
    {
        // Validamos que nos envíen el Token de PayPal y el ID del Tenant
        $request->validate([
            'token' => 'required', // El ID de la orden que manda PayPal en la URL
            'tenant_id' => 'required|exists:tenants,id'
        ]);

        $tenant = Tenant::find($request->tenant_id);

        // Si ya está activo, no hacemos nada (evita doble cobro o errores)
        if ($tenant->is_active) {
            return response()->json(['message' => 'La cuenta ya está activa.']);
        }

        $config = config('services.paypal');
        $clientId = $config['client_id'];
        $secret = $config['secret'];
        $mode = $config['mode'];
        $baseUrl = ($mode === 'sandbox') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        try {
            // 1. Obtener Token de Acceso (Igual que arriba)
            $auth = Http::withBasicAuth($clientId, $secret)
                ->asForm()->post("$baseUrl/v1/oauth2/token", ['grant_type' => 'client_credentials']);
            
            if ($auth->failed()) throw new \Exception('Error auth PayPal');
            $accessToken = $auth->json()['access_token'];

            // 2. CAPTURAR EL PAGO (Cobrar el dinero de la orden)
            // Usamos el 'token' que viene en la URL como el ID de la orden
            $captureResponse = Http::withToken($accessToken)
                ->post("$baseUrl/v2/checkout/orders/{$request->token}/capture", [
                    'headers' => ['Content-Type' => 'application/json']
                ]);

            // 3. Verificar si se completó
            $status = $captureResponse->json()['status'] ?? 'FAILED';

            if ($captureResponse->successful() && $status === 'COMPLETED') {
                
                // ¡ÉXITO! Activamos la cuenta
                $tenant->update([
                    'is_active' => true,
                    'subscription_ends_at' => now()->addMonth(), // Un mes de servicio
                    'external_payment_id' => $captureResponse->json()['id'] // Guardamos ID de transacción real
                ]);

                return response()->json(['message' => 'Cuenta activada correctamente']);
            } else {
                Log::error('PayPal Capture Failed', $captureResponse->json());
                return response()->json(['error' => 'El pago no se completó o fue rechazado.'], 400);
            }

        } catch (\Exception $e) {
            Log::error('Capture Exception: ' . $e->getMessage());
            return response()->json(['error' => 'Error al verificar el pago.'], 500);
        }
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
