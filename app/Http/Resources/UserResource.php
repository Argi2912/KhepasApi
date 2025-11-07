<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transforma el recurso en un array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // El frontend necesita 'id' y una combinación de 'name' y 'email' para el BaseSelect.
        // Además, incluimos el rol principal.
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            
            // Usamos whenLoaded para asegurar que los roles ya están cargados (como en el index())
            'roles' => $this->whenLoaded('roles', function () {
                // Devolvemos solo el nombre de los roles, ya que no necesitamos sus permisos aquí
                return $this->roles->pluck('name'); 
            }),
        ];
    }
}