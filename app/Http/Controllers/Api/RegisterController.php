<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Tenant, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Log};
use Stripe\StripeClient;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class RegisterController extends Controller
{
    private $plans = [
        'basic' => [
            'name' => 'Plan Inicial - Casa de Cambio',
            'price' => 10.00,
            'stripe_price_id' => 'price_123_basic',
            'paypal_plan_id'  => 'P-123_BASIC'
        ],
        'pro'   => [
            'name' => 'Plan Profesional - Multi Divisa',
            'price' => 29.99,
            'stripe_price_id' => 'price_456_pro',
            'paypal_plan_id'  => 'P-456_PRO'
        ]
    ];

    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255|unique:tenants,name',
            'admin_name'   => 'required|string|max:255',
            'admin_email'  => 'required|email|unique:users,email',
            'password'     => 'required|min:8|confirmed',
            'plan'         => 'required|in:basic,pro',
            'method'       => 'required|in:stripe,paypal'
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $planData = $this->plans[$request->plan];

                $tenant = Tenant::create([
                    'name' => $request->company_name,
                    'is_active' => false,
                    'plan_name' => $planData['name'],
                    'plan_price' => $planData['price'],
                    'payment_method' => $request->method
                ]);

                // Creamos el usuario admin
                User::create([
                    'name' => $request->admin_name,
                    'email' => $request->admin_email,
                    'password' => Hash::make($request->password),
                    'tenant_id' => $tenant->id,
                    'is_active' => true
                ]);

                if ($request->method === 'stripe') {
                    return $this->handleStripe($tenant, $planData);
                } else {
                    return $this->handlePayPal($tenant, $planData);
                }
            });
        } catch (\Exception $e) {
            Log::error("Error en registro: " . $e->getMessage());
            return response()->json(['error' => 'No se pudo procesar el registro.'], 500);
        }
    }

    private function handleStripe($tenant, $planData)
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        // 'mode' => 'subscription' crea automáticamente el cobro recurrente
        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $planData['stripe_price_id'],
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => url('/payment-success?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/register'),
            'client_reference_id' => (string)$tenant->id, // Para identificarlo en el Webhook
        ]);

        $tenant->update(['external_payment_id' => $session->id]);
        return response()->json(['url' => $session->url]);
    }

    private function handlePayPal($tenant, $plan)
    {
        try {
            $provider = new PayPalClient;
            $provider->setApiCredentials(config('paypal'));
            $provider->getAccessToken();

            // FORZAR URLS ANTES DE CREAR LA ORDEN
            $returnUrl = "http://localhost:5173/payment-success?tenant_id={$tenant->id}";
            $cancelUrl = "http://localhost:5173/register";

            $order = $provider->createOrder([
                "intent" => "CAPTURE",
                "application_context" => [
                    "brand_name"          => "KHEPAS",
                    "landing_page"        => "LOGIN",
                    "user_action"         => "PAY_NOW",
                    "return_url"          => $returnUrl,
                    "cancel_url"          => $cancelUrl,
                    "shipping_preference" => "NO_SHIPPING"
                ],
                "purchase_units" => [[
                    "reference_id" => (string)$tenant->id,
                    "amount" => [
                        "currency_code" => "USD",
                        "value" => number_format($plan['price'], 2, '.', '')
                    ]
                ]]
            ]);

            Log::info("PayPal Link Generado: ", ['url' => collect($order['links'])->where('rel', 'approve')->first()['href'] ?? 'N/A']);

            if (!isset($order['id'])) {
                return response()->json(['error' => 'Error al crear orden'], 422);
            }

            $tenant->update(['external_payment_id' => $order['id']]);
            $approveLink = collect($order['links'])->where('rel', 'approve')->first();

            return response()->json(['url' => $approveLink['href']]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
