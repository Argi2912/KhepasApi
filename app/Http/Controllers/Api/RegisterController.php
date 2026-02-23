<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Tenant, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Log};
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class RegisterController extends Controller
{
    // Agregamos el plan 'free'
    private $plans = [
        'free'  => [
            'name'  => 'Plan Gratuito - Prueba de 30 días',
            'price' => 0.00,
        ],
        'basic' => [
            'name'  => 'Plan Inicial - Casa de Cambio',
            'price' => 10.00,
            'paypal_plan_id'  => 'P-123_BASIC'
        ],
        'pro'   => [
            'name'  => 'Plan Profesional - Multi Divisa',
            'price' => 29.99,
            'paypal_plan_id'  => 'P-456_PRO'
        ]
    ];

    public function register(Request $request)
    {
        // Validamos que 'method' solo sea obligatorio si el plan no es gratis
        $request->validate([
            'company_name' => 'required|string|max:255|unique:tenants,name',
            'admin_name'   => 'required|string|max:255',
            'admin_email'  => 'required|email|unique:users,email',
            'password'     => 'required|min:8|confirmed',
            'plan'         => 'required|in:free,basic,pro',
            'method'       => 'required_if:plan,basic,pro|in:paypal'
        ]);

        try {
            DB::beginTransaction();

            // Si es plan gratis, lo activamos directo. Si es de pago, queda pendiente.
            $tenantStatus = $request->plan === 'free' ? true : false;

            $tenant = Tenant::create([
                'name'   => $request->company_name,
                'domain' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $request->company_name)) . '.localhost',
                'plan'   => $request->plan,
                'is_active' => $tenantStatus,
                // Si en tu BD tienes una columna para el periodo de prueba, puedes agregarla así:
                'subscription_ends_at' => $request->plan === 'free' ? now()->addDays(30) : null,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name'      => $request->admin_name,
                'email'     => $request->admin_email,
                'password'  => Hash::make($request->password),
                'role'      => 'admin',
            ]);

            $user->assignRole('admin_tenant');

            DB::commit();

            // Si el plan es gratuito, no llamamos a la pasarela, lo mandamos al login directo
            if ($request->plan === 'free') {
                return response()->json([
                    'message' => 'Registro exitoso. Disfruta tu prueba de 30 días.',
                    'url'     => 'https://www.tuconpay.com/login' // URL para que inicie sesión
                ]);
            }

            // Si es un plan de pago, procesamos con PayPal
            return $this->handlePayPal($tenant, $this->plans[$request->plan]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en registro: " . $e->getMessage());
            return response()->json(['error' => 'Error al registrar: ' . $e->getMessage()], 500);
        }
    }

    private function handlePayPal(Tenant $tenant, $plan)
    {
        try {
            $provider = new PayPalClient;
            $provider->setApiCredentials(config('paypal'));
            $provider->getAccessToken();

            $returnUrl = "https://www.tuconpay.com/payment-success?tenant_id={$tenant->id}";
            $cancelUrl = "https://www.tuconpay.com/register";

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
