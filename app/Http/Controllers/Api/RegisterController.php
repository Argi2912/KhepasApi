<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Tenant, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Log, Http};

class RegisterController extends Controller
{
    // Definición de Planes
    private $plans = [
        'basic' => [
            'name' => 'Plan Básico',
            'price' => 10.00,
            'description' => 'Suscripción Mensual - Plan Básico'
        ],
        'pro'   => [
            'name' => 'Plan Profesional',
            'price' => 29.99,
            'description' => 'Suscripción Mensual - Plan Profesional'
        ]
    ];

    public function register(Request $request)
    {
        // 1. Validación
        $request->validate([
            'company_name' => 'required|string|max:255|unique:tenants,name',
            'admin_name'   => 'required|string|max:255',
            'admin_email'  => 'required|email|unique:users,email',
            'password'     => 'required|min:8|confirmed',
            'plan'         => 'required|in:basic,pro',
            'method'       => 'required|in:stripe,paypal'
        ]);

        DB::beginTransaction();
        try {
            // 2. Obtener datos del plan seleccionado
            $planData = $this->plans[$request->plan];

            // 3. Crear el Tenant (INACTIVO)
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'is_active' => false, // <--- Nace inactivo hasta que pague
                'plan_name' => $planData['name'],
                'plan_price' => $planData['price'],
                'subscription_ends_at' => now()->addMonth(), // Fecha tentativa
            ]);

            // 4. Crear el Usuario Admin (ACTIVO)
            // El usuario debe estar activo para poder hacer login, 
            // pero el Middleware 'EnsureTenantIsActive' lo bloqueará después.
            $user = User::create([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => Hash::make($request->password),
                'tenant_id' => $tenant->id,
                'is_active' => true 
            ]);

            // Asignar rol (si usas Spatie)
            // $user->assignRole('admin');

            DB::commit();

            // 5. Generar enlace de pago según el método
            if ($request->method === 'stripe') {
                return $this->handleStripe($tenant, $planData);
            } else {
                return $this->handlePayPal($tenant, $planData);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en registro: " . $e->getMessage());
            return response()->json(['error' => 'No se pudo procesar el registro.'], 500);
        }
    }

    /**
     * Manejo de PayPal usando la configuración de services.php
     * (Igual que en SubscriptionController)
     */
    private function handlePayPal($tenant, $planData)
    {
        try {
            // Cargar configuración desde config/services.php
            $config = config('services.paypal');
            $clientId = $config['client_id'];
            $secret = $config['secret'];
            $mode = $config['mode'];
            $currency = $config['currency'] ?? 'USD';

            $baseUrl = ($mode === 'sandbox') 
                ? 'https://api-m.sandbox.paypal.com' 
                : 'https://api-m.paypal.com';

            // 1. Obtener Token de Acceso
            $authResponse = Http::withBasicAuth($clientId, $secret)
                ->asForm()
                ->post("$baseUrl/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($authResponse->failed()) {
                Log::error('PayPal Auth Error', $authResponse->json());
                throw new \Exception('Error de autenticación con PayPal');
            }

            $accessToken = $authResponse->json()['access_token'];

            // 2. URLs de Retorno (Frontend)
            // IMPORTANTE: Pasamos el tenant_id para saber a quién activar al volver
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            $returnUrl = "$frontendUrl/payment-success?tenant_id={$tenant->id}";
            $cancelUrl = "$frontendUrl/login"; // Si cancela, lo mandamos al login (donde verá que está bloqueado)

            // 3. Crear Orden
            $orderResponse = Http::withToken($accessToken)
                ->post("$baseUrl/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => "REG_{$tenant->id}",
                        'description' => $planData['description'],
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => number_format($planData['price'], 2, '.', '')
                        ]
                    ]],
                    'application_context' => [
                        'brand_name' => 'TuConpay',
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl
                    ]
                ]);

            if ($orderResponse->failed()) {
                Log::error('PayPal Order Error', $orderResponse->json());
                throw new \Exception('Error creando la orden de pago');
            }

            $order = $orderResponse->json();

            // Guardamos el ID de orden temporalmente (opcional)
            $tenant->update(['external_payment_id' => $order['id']]);

            // 4. Extraer enlace de aprobación
            $approveLink = null;
            foreach ($order['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveLink = $link['href'];
                    break;
                }
            }

            return response()->json(['url' => $approveLink]);

        } catch (\Exception $e) {
            Log::error('PayPal Exception: ' . $e->getMessage());
            // Si falla PayPal, no borramos el usuario, pero queda inactivo.
            // El usuario podrá intentar pagar después al hacer login.
            return response()->json(['error' => 'Registro creado, pero falló la generación del pago. Intente iniciar sesión.'], 500);
        }
    }

    /**
     * Manejo de Stripe (Placeholder - Mantén tu lógica si la usas luego)
     */
    private function handleStripe($tenant, $planData)
    {
        // ... Tu código de Stripe existente o comentado ...
        return response()->json(['error' => 'Stripe no está habilitado actualmente.'], 400);
    }
}