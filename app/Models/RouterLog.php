<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['router_id', 'waktu', 'topik', 'pengguna', 'ip', 'kejadian', 'pesan', 'hash'];

    protected $casts = ['waktu' => 'datetime'];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
