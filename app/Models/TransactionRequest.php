<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionRequest extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    // Estados constantes para evitar errores de dedo
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSED = 'processed';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'type',              // 'exchange', 'withdrawal'
        'source_origin',     // Texto libre: 'Zelle', 'Banco', etc.
        'destination_target',// Texto libre: 'Efectivo', 'USDT', etc.
        'amount',
        'currency_code',     // USD, VES, etc.
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // --- Relaciones ---

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    
    // --- Scopes (Filtros útiles) ---

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}