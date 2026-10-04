<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherSale extends Model
{
    protected $table = 'voucher_sales';

    protected $guarded = ['id'];

    protected $casts = [
        'sold_at'                => 'datetime',
        'waktu_ragu'             => 'boolean',
        'price'                  => 'integer',
        'sprice'                 => 'integer',
        'dihapus_dari_router_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
