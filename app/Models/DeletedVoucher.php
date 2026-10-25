<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletedVoucher extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'nomor'        => 'integer',
        'price'        => 'integer',
        'uptime_used'  => 'integer',
        'activated_at' => 'datetime',
        'expired_at'   => 'datetime',
        'printed_at'   => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    public function penghapus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'ready'    => 'Belum dipakai',
            'active'   => 'Aktif',
            'expired'  => 'Habis',
            'disabled' => 'Dinonaktifkan',
            'missing'  => 'Hilang dari router',
            default    => (string) $this->status,
        };
    }

    public function getCaraLabelAttribute(): string
    {
        return match ($this->cara) {
            'satu'   => 'Hapus satu kartu',
            'massal' => 'Hapus massal',
            'batch'  => 'Hapus batch',
            default  => (string) $this->cara,
        };
    }

    public function getHasilRouterLabelAttribute(): string
    {
        return match ($this->hasil_router) {
            'dicabut'         => 'Dicabut dari router',
            'tidak_ada'       => 'Sudah tidak ada di router',
            'beda_password'   => 'Tidak dicabut, password di router beda',
            'gagal'           => 'Gagal dicabut',
            'tidak_dicabut'   => 'Tidak dicabut dari router',
            'tidak_diketahui' => 'Hasil cabut tidak diketahui',
            default           => (string) $this->hasil_router,
        };
    }
}
