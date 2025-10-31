<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'provider_id' => $this->provider_id,
            'amount' => $this->amount,
            'status' => $this->status,
            
            'client_name' => $this->client->first_name . ' ' . $this->client->last_name ?? 'N/A',
            'provider_name' => $this->provider->name ?? 'N/A',
            'currency_code' => $this->currency->code ?? 'N/A',

            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
