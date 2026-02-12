<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory, BelongsToTenant, Filterable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'description',
        'amount',
        'original_amount',
        'paid_amount',
        'pending_amount',
        'currency_id',
        'currency_code', // ✅ CRÍTICO: Esto permite guardar el texto "VES", "USD"
        'type',
        'status',
        'entity_type',
        'entity_id',
        'transaction_type',
        'transaction_id',
        'due_date',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'original_amount' => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'pending_amount'  => 'decimal:2',
        'due_date'        => 'date',
    ];

    // --- RELACIONES ---

    public function payments()
    {
        return $this->hasMany(LedgerPayment::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function transaction(): MorphTo
    {
        return $this->morphTo();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    // --- ACCESORES ---

    public function getPendingAmountAttribute($value)
    {
        if (!is_null($value)) {
            return $value;
        }
        return $this->original_amount - $this->paid_amount;
    }

    public function getStatusAttribute($value)
    {
        if ($value) return $value;

        if ($this->paid_amount >= $this->original_amount) {
            return 'paid';
        }
        if ($this->paid_amount > 0) {
            return 'partially_paid';
        }
        return 'pending';
    }

    // --- SCOPES ---

    public function scopeStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeType(Builder $query, $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeEntity(Builder $query, $entityType, $entityId): Builder
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * ✅ CORRECCIÓN DEFINITIVA: Automatización de Datos
     * Esto se ejecuta justo antes de guardar en la base de datos.
     */
    protected static function booted()
    {
        static::creating(function ($entry) {
            // 1. Calcular montos pendientes automáticamente
            if (is_null($entry->original_amount)) {
                $entry->original_amount = $entry->amount;
            }
            
            $entry->pending_amount = $entry->original_amount - ($entry->paid_amount ?? 0);

            // 2. 🔥 AUTO-ASIGNAR EL CÓDIGO DE MONEDA 🔥
            // Si nos dieron el ID (ej: 7) pero no el Código (ej: VES), lo buscamos y rellenamos.
            if (!empty($entry->currency_id) && empty($entry->currency_code)) {
                $currency = \App\Models\Currency::find($entry->currency_id);
                if ($currency) {
                    $entry->currency_code = $currency->code;
                }
            }
        });
    }
}