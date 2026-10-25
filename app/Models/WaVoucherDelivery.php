<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaVoucherDelivery extends Model
{
    public const ANTRE       = 'antre';
    public const DIPROSES    = 'diproses';
    public const TERKIRIM    = 'terkirim';
    public const GAGAL       = 'gagal';
    public const BELUM_PASTI = 'belum_pasti';
    public const DIBATALKAN  = 'dibatalkan';

    public const BASI_ANTRE_MENIT    = 15;
    public const BASI_DIPROSES_MENIT = 5;

    protected $fillable = ['username', 'phone', 'status', 'reason', 'message_id', 'sent_at', 'created_by'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function isAktif(): bool
    {
        return in_array($this->status, [self::ANTRE, self::DIPROSES], true);
    }

    public function isBasi(): bool
    {
        $batas = match ($this->status) {
            self::ANTRE    => self::BASI_ANTRE_MENIT,
            self::DIPROSES => self::BASI_DIPROSES_MENIT,
            default        => null,
        };

        return $batas !== null && $this->updated_at !== null && $this->updated_at->lt(now()->subMinutes($batas));
    }
}
