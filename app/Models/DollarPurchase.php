<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder; // <-- 2. IMPORTAR

class DollarPurchase extends Model
{
   use HasFactory, BelongsToTenant, LogsActivity, Filterable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'number',
        'client_id',
        'broker_id',
        'provider_id',
        'admin_user_id',
        'platform_account_id',
        'amount_received',
        'deliver_currency_code',
        'buy_rate',
        'received_rate',
        'commission_charged_pct',
        'commission_provider_pct',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_received' => 'decimal:2',
        'buy_rate' => 'decimal:2',
        'received_rate' => 'decimal:2',
        'commission_charged_pct' => 'decimal:2',
        'commission_provider_pct' => 'decimal:2',
    ];

    //--- Relaciones ---

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'platform_account_id');
    }
    
    /**
     * Asientos contables generados por esta transacción
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'transaction');
    }

    //--- Auditoría ---

    /**
     * @return \Spatie\Activitylog\LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "Compra de Dólar #{$this->number} fue {$eventName}");
    }

    // --- LOCAL SCOPES (FILTROS) ---

    public function scopeClientId(Builder $query, $id): Builder
    {
        return $query->where('client_id', $id);
    }

    public function scopeBrokerId(Builder $query, $id): Builder
    {
        return $query->where('broker_id', $id);
    }

    public function scopeProviderId(Builder $query, $id): Builder
    {
        return $query->where('provider_id', $id);
    }

    public function scopeAdminUserId(Builder $query, $id): Builder
    {
        return $query->where('admin_user_id', $id);
    }
}
