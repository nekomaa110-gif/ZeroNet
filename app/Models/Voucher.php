<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'batch_id', 'router_id', 'username', 'password', 'profile', 'server', 'comment',
        'limit_uptime', 'limit_bytes', 'price',
        'status', 'sync_status', 'sync_error', 'synced_at',
        'printed_at', 'activated_at', 'expired_at', 'uptime_used', 'checked_at',
    ];

    protected $casts = [
        'limit_bytes'  => 'integer',
        'price'        => 'integer',
        'uptime_used'  => 'integer',
        'synced_at'    => 'datetime',
        'printed_at'   => 'datetime',
        'activated_at' => 'datetime',
        'expired_at'   => 'datetime',
        'checked_at'   => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(VoucherBatch::class, 'batch_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function getStatusEfektifAttribute(): string
    {
        return $this->status === 'active' && $this->expired_at?->lte(now()->subSeconds(self::jedaPengawas()))
            ? 'expired'
            : $this->status;
    }

    public static function jedaPengawas(): int
    {
        [$jam, $menit, $detik] = array_map('intval', explode(':', (string) config('voucher.scheduler_interval', '00:03:00')) + [0, 0, 0]);

        return $jam * 3600 + $menit * 60 + $detik + 60;
    }

    public static function statusEfektifSql(): string
    {
        return "CASE WHEN status = 'active' AND expired_at IS NOT NULL AND expired_at <= ? THEN 'expired' ELSE status END";
    }

    public static function ikatanStatusEfektif(): array
    {
        return [now()->subSeconds(self::jedaPengawas())];
    }

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $status
            ? $q->whereRaw(self::statusEfektifSql() . ' = ?', [...self::ikatanStatusEfektif(), $status])
            : $q;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status_efektif) {
            'ready'    => 'Belum dipakai',
            'active'   => 'Aktif',
            'expired'  => 'Habis',
            'disabled' => 'Dinonaktifkan',
            'missing'  => 'Hilang dari router',
            default    => $this->status,
        };
    }

    public function getStatusToneAttribute(): string
    {
        return match ($this->status_efektif) {
            'ready'    => '',
            'active'   => 'ok',
            'expired'  => 'warn',
            'disabled' => 'err',
            'missing'  => 'err',
            default    => '',
        };
    }
}
