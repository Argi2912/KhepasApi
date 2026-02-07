<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Tenant, Account};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class WebhookController extends Controller
{
    public function handleStripe(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            return response()->json(['error' => 'Firma o Payload inválido'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $this->activateTenant($session->client_reference_id, 'stripe', $session->id);
        }

        return response()->json(['status' => 'success']);
    }

    public function handlePayPal(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['event_type'] ?? '';

        Log::info("PAYPAL DATA COMPLETA:", $request->all());
        Log::info("PayPal Webhook Recibido: " . $eventType);

        if (in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED'])) {
            $resource = $payload['resource'];
            $tenantId = null;

            // Caso A: El ID viene en purchase_units (Checkout estándar)
            if (isset($resource['purchase_units'][0]['reference_id'])) {
                $tenantId = $resource['purchase_units'][0]['reference_id'];
            }
            // Caso B: El ID viene en custom_id (Suscripciones o links directos)
            elseif (isset($resource['custom_id'])) {
                $tenantId = $resource['custom_id'];
            }
            // Caso C: Captura directa (A veces el reference_id sube un nivel en el JSON)
            elseif (isset($payload['resource']['supplementary_data']['related_ids']['order_id'])) {
                // Aquí podrías buscar la orden si fuera necesario, pero el reference_id 
                // suele estar en el array de purchase_units del recurso.
            }

            Log::info("ID del Tenant detectado en Webhook: " . ($tenantId ?? 'No encontrado'));

            if ($tenantId) {
                $this->activateTenant($tenantId, 'paypal', $resource['id']);
                return response()->json(['status' => 'success', 'message' => 'Tenant activated']);
            } else {
                Log::warning("Webhook recibido pero no se encontró Tenant ID. Payload:", $payload);
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function activateTenant($tenantId, $method, $externalId)
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant && !$tenant->is_active) {
            $tenant->update([
                'is_active' => true,
                'payment_method' => $method,
                'external_payment_id' => $externalId
            ]);

            Account::create([
                'tenant_id' => $tenant->id,
                'name' => 'Caja Principal USD',
                'currency_code' => 'USD',
                'balance' => 0.00
            ]);

            Log::info("Tenant {$tenantId} activado con éxito.");
        }
    }
}
