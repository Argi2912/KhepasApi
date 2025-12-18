<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// <-- 2. IMPORTAR

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
        'currency_type',
        'type',        // 'payable' o 'receivable'
        'status',      // 'pending' o 'paid'
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
        'amount'   => 'decimal:2',
        'due_date' => 'date',
    ];

    public function payments()
    {
        return $this->hasMany(LedgerPayment::class);
    }

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

    // Accessor para monto pendiente (calculado)
    public function getPendingAmountAttribute()
    {
        return $this->original_amount - $this->paid_amount;
    }

    // Actualizar status automáticamente
    public function getStatusAttribute($value)
    {
        if ($this->paid_amount >= $this->original_amount) {
            return 'paid';
        }
        if ($this->paid_amount > 0) {
            return 'partially_paid';
        }
        return 'pending';
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

    protected static function booted()
    {
        static::creating(function ($payment) {
            // Cargar el asiento relacionado
            $entry = $payment->ledgerEntry;

            if ($entry && $entry->currency_type !== $payment->currency_type) {
                throw new Exception(
                    "El pago debe ser en la misma moneda que el asiento contable. " .
                        "Asiento: {$entry->currency_type}, Pago: {$payment->currency_type}"
                );
            }

            // Opcional: también validar que la cuenta usada tenga la misma moneda
            if ($payment->account && $payment->account->currency_code !== $payment->currency_type) {
                throw new Exception(
                    "La cuenta seleccionada ({$payment->account->name}) opera en {$payment->account->currency_code}, " .
                        "pero el pago está en {$payment->currency_type}"
                );
            }
        });

        // También validar al actualizar (por si alguien edita manualmente)
        static::updating(function ($payment) {
            $entry = $payment->ledgerEntry()->withTrashed()->first(); // por si está soft deleted

            if ($entry && $entry->currency_type !== $payment->currency_type) {
                throw new Exception("No se puede cambiar la moneda del pago a una diferente del asiento original.");
            }
        });
    }
}
