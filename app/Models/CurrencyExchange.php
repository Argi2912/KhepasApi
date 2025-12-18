<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CurrencyExchange extends Model
{
    use HasFactory, BelongsToTenant, LogsActivity, Filterable;

    protected $fillable = [
        'tenant_id',
        'number', // CE-00001

        // Participantes
        'client_id',
        'admin_user_id', // Quien registra
        'broker_id',     // Opcional
        'provider_id',   // Opcional
        'platform_id',   // Plataforma usada (Ej: Binance, Coinbase, etc.)

        // Flujo de Dinero
        'from_account_id', // Cuenta que envía (Sale dinero)
        'to_account_id',   // Cuenta que recibe (Entra dinero)

        // Datos Financieros (Manuales)
        'amount_sent',     // Monto Salida
        'amount_received', // Monto Entrada
        'exchange_rate',   // Tasa Manual
        'buy_rate',

        // Comisiones (Montos exactos)
        'commission_total_amount',
        'commission_provider_amount',
        'commission_admin_amount',
        'commission_broker_amount',

        // Trazabilidad
        'trader_info',  // Ej: Pepito27 - Binance
        'reference_id', // Hash o ID externo
        'status',

        'capital_type', // 'own', 'investor'
        'investor_id',
        'investor_profit_pct',
        'investor_profit_amount',

        'currency_sent',
        'currency_received',
    ];

    protected $casts = [
        'amount_sent'                => 'decimal:2',
        'amount_received'            => 'decimal:2',
        'exchange_rate'              => 'decimal:8', // Alta precisión para tasas
        'buy_rate'                   => 'decimal:8',
        'commission_total_amount'    => 'decimal:2',
        'commission_provider_amount' => 'decimal:2',
        'commission_admin_amount'    => 'decimal:2',
        'investor_profit_amount'     => 'decimal:2',
        'investor_profit_pct'        => 'decimal:2',
    ];

    // --- Relaciones ---

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'transaction');
    }

    // --- Auditoría ---

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "Intercambio #{$this->number} fue {$eventName}");
    }

    // --- Scopes ---

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function investor(): BelongsTo
    {
        // Ajusta 'Investor::class' a tu modelo real (ej: Partner::class)
        return $this->belongsTo(Investor::class);
    }
}
