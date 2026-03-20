<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sender_id',
        'tenant_id',
        'subject',
        'body',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /**
     * El usuario dueño del ticket (el cliente).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Quién envió este mensaje específico.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
