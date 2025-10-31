<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'company_name' => $this->company_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
