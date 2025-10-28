<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Solicitud extends Model
{
    use HasFactory;

    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fecha',
        'monto',
        'comisionCobrada',
        'comisionProveedor',
        'comisionAdmin',
        'origen',
        'destino',
        'numero',
        
        // --- Campos de Relación (Foreign Keys) ---
        'request_type_id', // Para el "Tipo de Solicitud"
        'client_id',       // ID del cliente
        'provider_id',     // ID del proveedor
        'corredor_id',     // ID del corredor
        'admin_id',        // ID del admin
        
        // Nota: 'nombreCliente' ya no se guarda, se guarda 'client_id'.
        // La base de datos debe tener estas columnas.
    ];

    // --- (Opcional) Definir relaciones ---

    public function requestType()
    {
        return $this->belongsTo(RequestType::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    
    // ... (relaciones para provider, corredor, admin)
}