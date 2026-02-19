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
        'free' => [
            'name' => 'Plan Gratuito',
            'price' => 0.00,
            'description' => 'Prueba de 7 días',
            'days' => 7
        ],
        'basic' => [
            'name' => 'Plan Básico',
            'price' => 10.00,
            'description' => 'Suscripción Mensual - Plan Básico',
            'days' => 30
        ],
        'pro'   => [
            'name' => 'Plan Profesional',
            'price' => 29.99,
            'description' => 'Suscripción Mensual - Plan Profesional',
            'days' => 30
        ]
    ];

    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255|unique:tenants,name',
            'admin_name'   => 'required|string|max:255',
            'admin_email'  => 'required|email|unique:users,email',
            'password'     => 'required|min:8|confirmed',
            'plan'         => 'required|in:free,basic,pro',
        ]);

        return DB::transaction(function () use ($request) {
            try {
                $planData = $this->plans[$request->plan];
                $isFree = $request->plan === 'free';

                // 1. Crear el Tenant
                $tenant = Tenant::create([
                    'name' => $request->company_name,
                    'plan_name' => $planData['name'],
                    'plan_price' => $planData['price'],
                    'is_active' => $isFree, // Solo se activa si es gratis
                    'subscription_ends_at' => now()->addDays($planData['days']),
                ]);

                // 2. Crear el Usuario Admin (Usando la lógica de tus otros controladores)
                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $request->admin_name,
                    'email' => $request->admin_email,
                    'password' => Hash::make($request->password),
                    'is_active' => true
                ]);

                // 3. Asignar Rol (admin_tenant es el que usas en TenantController)
                $user->assignRole('admin_tenant');

                // 4. Lógica de salida: Gratis vs Pago
                if ($isFree) {
                    return response()->json([
                        'message' => 'Cuenta gratuita creada por 7 días.',
                        'redirect_to' => 'login'
                    ], 201);
                }

                // Si es de pago, generamos PayPal
                return $this->handlePayPal($tenant, $planData);
            } catch (\Exception $e) {
                Log::error("Error en registro: " . $e->getMessage());
                return response()->json(['error' => 'Error procesando el registro'], 500);
            }
        });
    }

    private function handlePayPal($tenant, $planData)
    {
        // NOTA: Asegúrate de tener tus credenciales en el .env
        $clientId = config('services.paypal.client_id');
        $secret = config('services.paypal.secret');
        $baseUrl = config('services.paypal.base_url'); // https://api-m.sandbox.paypal.com

        try {
            // Obtener Token
            $auth = Http::withBasicAuth($clientId, $secret)
                ->asForm()->post("$baseUrl/v1/oauth2/token", ['grant_type' => 'client_credentials']);

            $token = $auth->json()['access_token'];

            // Crear Orden
            $order = Http::withToken($token)->post("$baseUrl/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($planData['price'], 2, '.', '')
                    ],
                    'description' => "Pago de {$planData['name']} - {$tenant->name}"
                ]],
                'application_context' => [
                    'return_url' => route('paypal.success', ['tenant' => $tenant->id]),
                    'cancel_url' => route('paypal.cancel'),
                ]
            ])->json();

            $approveLink = collect($order['links'])->where('rel', 'approve')->first()['href'];

            return response()->json(['url' => $approveLink]);
        } catch (\Exception $e) {
            Log::error('PayPal Error: ' . $e->getMessage());
            return response()->json(['error' => 'Error al generar link de pago, pero tu usuario fue creado. Intenta pagar desde el login.'], 500);
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
