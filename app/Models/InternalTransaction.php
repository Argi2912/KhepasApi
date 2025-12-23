<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToTenant; 

class InternalTransaction extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'account_id',
        'user_id',
        'type', 
        'category',
        'amount',
        'description',
        'transaction_date',
        'dueño',
        'person_name',
        'source_type',
        // Estos son los campos clave para la relación
        'entity_type',
        'entity_id'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 👇 ESTA ES LA FUNCIÓN QUE TE FALTA 👇
     * Agregala para que desaparezca el error "undefined relationship [entity]"
     */
    public function entity()
    {
        return $this->morphTo();
    }
}