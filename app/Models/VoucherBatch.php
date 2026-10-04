<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherBatch extends Model
{
    protected $fillable = [
        'router_id', 'router_name', 'code', 'label', 'profile', 'server', 'quantity',
        'mode', 'charset', 'code_length', 'password_length', 'prefix', 'suffix',
        'limit_uptime', 'limit_bytes', 'validity', 'price',
        'status', 'source', 'synced_count', 'failed_count', 'error',
        'synced_at', 'printed_at', 'created_by',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'code_length'     => 'integer',
        'password_length' => 'integer',
        'limit_bytes'     => 'integer',
        'price'           => 'integer',
        'synced_count'    => 'integer',
        'failed_count'    => 'integer',
        'synced_at'       => 'datetime',
        'printed_at'      => 'datetime',
    ];

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'batch_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sedangSinkron(): bool
    {
        return $this->status === 'syncing' && $this->updated_at?->gt(now()->subMinutes(15));
    }

    public function getProgressAttribute(): int
    {
        if ($this->quantity <= 0) {
            return 0;
        }

        return min(100, (int) round(($this->synced_count + $this->failed_count) / $this->quantity * 100));
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Menunggu',
            'syncing' => 'Sinkronisasi',
            'success' => 'Berhasil',
            'partial' => 'Sebagian gagal',
            'failed'  => 'Gagal',
            default   => $this->status,
        };
    }

    public function getStatusToneAttribute(): string
    {
        return match ($this->status) {
            'success' => 'ok',
            'partial' => 'warn',
            'failed'  => 'err',
            'syncing' => 'info',
            default   => '',
        };
    }

    public function getRouterLabelAttribute(): string
    {
        return $this->router?->name ?? $this->router_name ?? '(router dihapus)';
    }
}
