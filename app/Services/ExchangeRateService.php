<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Auth;

class ExchangeRateService
{
    /**
     * Crea o actualiza una tasa de cambio y su inversa.
     * El ActivityLog registrará el cambio automáticamente.
     *
     * @param array $data Validated data [from_currency, to_currency, rate]
     * @return \App\Models\ExchangeRate
     */
    public function createRate(array $data)
    {
        $tenantId = Auth::guard('api')->user()->tenant_id;
        $rate = (float) $data['rate'];
        
        // Evita división por cero
        if ($rate == 0) {
            throw new \InvalidArgumentException('La tasa de cambio no puede ser cero.');
        }

        $inverseRate = 1 / $rate;

        // 1. Crea o actualiza la tasa principal (Ej: USD -> VES)
        $mainRate = ExchangeRate::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'from_currency' => $data['from_currency'],
                'to_currency' => $data['to_currency'],
            ],
            [
                'rate' => $rate
            ]
        );

        // 2. Crea o actualiza la tasa inversa (Ej: VES -> USD)
        ExchangeRate::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'from_currency' => $data['to_currency'],
                'to_currency' => $data['from_currency'],
            ],
            [
                'rate' => $inverseRate
            ]
        );

        return $mainRate;
    }
}