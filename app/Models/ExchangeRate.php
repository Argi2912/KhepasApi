<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\Filterable; // <-- 1. IMPORTAR
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Builder; // <-- 2. IMPORTAR

class ExchangeRate extends Model
{
    use HasFactory, BelongsToTenant, LogsActivity, Filterable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'from_currency',
        'to_currency',
        'rate',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rate' => 'decimal:8',
    ];

    /**
     * @return \Spatie\Activitylog\LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['rate']) // Solo nos importa el cambio en la tasa
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "Tasa {$this->from_currency}-{$this->to_currency} fue {$eventName}");
    }

    /**
     * Filtra por moneda de origen.
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $code
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromCurrency(Builder $query, $code): Builder
    {
        return $query->where('from_currency', $code);
    }

    /**
     * Filtra por moneda de destino.
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $code
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeToCurrency(Builder $query, $code): Builder
    {
        return $query->where('to_currency', $code);
    }
}
