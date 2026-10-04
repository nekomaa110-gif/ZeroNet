<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaBroadcast extends Model
{
    protected $fillable = ['user_id', 'target', 'message', 'total', 'status', 'finished_at'];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    public const TARGET_LABELS = [
        'all'      => 'Semua kontak',
        'active'   => 'Akun aktif',
        'expiring' => 'Akan expired 7 hari',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(WaBroadcastRecipient::class, 'broadcast_id');
    }

    public function counts(): array
    {
        $base = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];

        $nyata = $this->recipients()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        return array_merge($base, $nyata);
    }

    public function closeIfDone(): void
    {
        if ($this->status !== 'running') {
            return;
        }

        $sisa = $this->recipients()->whereIn('status', ['pending', 'sending'])->count();

        if ($sisa === 0) {
            $this->forceFill(['status' => 'finished', 'finished_at' => now()])->save();
        }
    }
}
