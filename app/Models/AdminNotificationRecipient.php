<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AdminNotificationRecipient extends Model
{
    protected $fillable = ['name', 'phone', 'events', 'is_active'];

    protected $casts = [
        'events'    => 'array',
        'is_active' => 'boolean',
    ];

    public const EVENTS = [
        'renewal_request'  => 'Pelanggan minta perpanjangan',
        'proof_uploaded'   => 'Bukti transfer masuk',
        'reminder_summary' => 'Ringkasan reminder expired',
        'daily_summary'    => 'Ringkasan harian',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public static function phonesFor(string $event): array
    {
        return static::active()
            ->orderBy('id')
            ->get()
            ->filter(fn (self $r) => $r->wants($event))
            ->pluck('phone')
            ->unique()
            ->values()
            ->all();
    }

    public function wants(string $event): bool
    {
        if (! array_key_exists($event, self::EVENTS)) {
            return true;
        }

        return in_array($event, (array) $this->events, true);
    }

    public function eventLabels(): array
    {
        return array_values(array_intersect_key(self::EVENTS, array_flip((array) $this->events)));
    }
}
