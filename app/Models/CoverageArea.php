<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CoverageArea extends Model
{
    public const STATUS_TERSEDIA = 'tersedia';
    public const STATUS_SEGERA   = 'segera';

    public const STATUSES = [self::STATUS_TERSEDIA, self::STATUS_SEGERA];

    protected $fillable = ['name', 'status', 'note', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function statusLabel(): string
    {
        return $this->status === self::STATUS_TERSEDIA ? 'Tersedia' : 'Segera Hadir';
    }
}
