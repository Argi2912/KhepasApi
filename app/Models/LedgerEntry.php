<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder; // <-- 2. IMPORTAR

class LedgerEntry extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'description',
        'amount',
        'type', // 'payable' o 'receivable'
        'status', // 'pending' o 'paid'
        'entity_type', // A quién: Provider, Broker, Client
        'entity_id',
        'transaction_type', // Qué lo originó: DollarPurchase, CurrencyExchange
        'transaction_id',
        'due_date',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    /**
     * La entidad relacionada (el Proveedor, Corredor, etc. a quien se le debe)
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * La transacción que originó esta entrada contable
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function transaction(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filtra por status (pending, paid)
     */
    public function scopeStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Filtra por type (payable, receivable)
     */
    public function scopeType(Builder $query, $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Filtra por la entidad polimórfica (ej. Broker con ID 5)
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $entityType (ej. 'App\Models\Broker')
     * @param  int  $entityId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEntity(Builder $query, $entityType, $entityId): Builder
    {
        return $query->where('entity_type', $entityType)
                     ->where('entity_id', $entityId);
    }
}
