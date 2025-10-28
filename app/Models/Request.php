<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'commission_charged',
        'supplier_commission',
        'admin_commission',
        'source_currency_id',
        'destination_currency_id',
        'destination_amount',
        'applied_exchange_rate',
        'request_type_id',
        'status',
        'rejection_reason',
        'client_id',
        'broker_id',
        'supplier_id',
        'admin_id',
        'source_platform_id',
        'destination_platform_id',
    ];

    protected $casts = [
        'amount' => 'decimal:5',
        'commission_charged' => 'decimal:5',
        'supplier_commission' => 'decimal:5',
        'admin_commission' => 'decimal:5',
        'destination_amount' => 'decimal:5',
        'applied_exchange_rate' => 'decimal:8',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // --- RELACIONES CON PLATAFORMAS Y MONEDAS ---

    public function sourceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'source_currency_id');
    }

    public function destinationCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'destination_currency_id');
    }

    public function sourcePlatform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'source_platform_id');
    }

    public function destinationPlatform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'destination_platform_id');
    }

    // --- OTRAS RELACIONES ---

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class, 'request_type_id');
    }

    /**
     * Obtiene los movimientos de caja asociados con esta solicitud.
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
