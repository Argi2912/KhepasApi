<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class InternalTransaction extends Model
{
    use HasFactory, BelongsToTenant, Filterable, LogsActivity;

    const TYPE_INCOME = 'income';
    const TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'tenant_id',
        'user_id',      // El responsable (Admin/Cajero)
        'account_id',   // La cuenta afectada
        'type',         // 'income' o 'expense'
        'category',     // 'Sueldos', 'Servicios', etc.
        'amount',
        'description',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    // --- Relaciones ---

    /**
     * Usuario responsable del movimiento
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cuenta donde entra o sale el dinero
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // --- Auditoría ---
    
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "Movimiento Interno #{$this->id} fue {$eventName}");
    }
}