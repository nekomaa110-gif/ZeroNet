<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerContact extends Model
{
    protected $fillable = ['username', 'name', 'phone', 'reminder_sent_at', 'notes'];

    protected $casts = [
        'reminder_sent_at' => 'datetime',
    ];

    public function scopeSearch(Builder $q, ?string $s): Builder
    {
        if (!$s) return $q;
        return $q->where(function ($x) use ($s) {
            $x->where('username', 'like', "%{$s}%")
              ->orWhere('phone', 'like', "%{$s}%")
              ->orWhere('name', 'like', "%{$s}%");
        });
    }
}
