<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Tenant, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Log};

class RegisterController extends Controller
{
    private $plans = [
        'free'  => [
            'name'  => 'Plan Gratuito - 1 mes',
            'price' => 0.00,
            'duration_months' => 1
        ],
        'normal' => [
            'name'  => 'Plan Normal',
            'price' => 47.00,
            'duration_months' => 1
        ],
        'pro'   => [
            'name'  => 'Plan Profesional',
            'price' => 300.00,
            'duration_months' => 13
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
            'plan'         => 'required|in:free,normal,pro',
            'method'       => 'required_if:plan,normal,pro|in:paypal'
        ]);

        try {
            DB::beginTransaction();

            // Si es plan gratis, lo activamos directo. Si es de pago, queda pendiente.
            $tenantStatus = $request->plan === 'free' ? true : false;

            $tenant = Tenant::create([
                'name'   => $request->company_name,
                'domain' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $request->company_name)) . '.localhost',
                'plan_name'   => $request->plan,
                'plan_price'  => $this->plans[$request->plan]['price'],
                'is_active' => $tenantStatus,
                'last_payment_at' => $request->plan === 'free' ? now() : null,
                'expires_at' => $request->plan === 'free' ? now()->addMonths($this->plans['free']['duration_months']) : null,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name'      => $request->admin_name,
                'email'     => $request->admin_email,
                'password'  => $request->password, // El modelo User se encarga del hasheo
                'role'      => 'admin',
                'is_active' => true,
            ]);

            $user->assignRole('admin_tenant');

            DB::commit();

            $token = Auth::guard('api')->login($user);

            // Si el plan es gratuito, mandamos al login directo con su token
            if ($request->plan === 'free') {
                return response()->json([
                    'message'      => 'Registro exitoso. Disfruta tu prueba de 30 días.',
                    'url'          => 'https://www.tuconpay.com/login',
                    'access_token' => $token,
                    'tenant_id'    => $tenant->id
                ]);
            }

            // Si es un plan de pago, respondemos con token para que el frontend procese con su componente PayPal
            return response()->json([
                'access_token' => $token,
                'tenant_id'    => $tenant->id,
                'message'      => 'Registro pre-configurado. Procede con el pago para activar.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en registro: " . $e->getMessage());
            return response()->json(['error' => 'Error al registrar: ' . $e->getMessage()], 500);
        }
    }
}
