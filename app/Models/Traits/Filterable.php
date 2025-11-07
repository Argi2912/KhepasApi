<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait Filterable
{
    /**
     * Filtra registros creados DESDE una fecha específica.
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $date (formato Y-m-d)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromDate(Builder $query, $date): Builder
    {
        return $query->whereDate('created_at', '>=', $date);
    }

    /**
     * Filtra registros creados HASTA una fecha específica.
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $date (formato Y-m-d)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeToDate(Builder $query, $date): Builder
    {
        return $query->whereDate('created_at', '<=', $date);
    }
}