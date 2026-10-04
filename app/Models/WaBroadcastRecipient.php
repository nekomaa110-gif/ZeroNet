<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaBroadcastRecipient extends Model
{
    protected $fillable = [
        'broadcast_id', 'contact_id', 'username', 'name',
        'phone', 'status', 'attempt', 'error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(WaBroadcast::class, 'broadcast_id');
    }
}
