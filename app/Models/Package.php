<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'groupname', 'display_name', 'speed_label', 'description', 'is_active',
        'price', 'show_price', 'validity_days', 'public_description', 'is_public', 'sort_order',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_public'     => 'boolean',
        'show_price'    => 'boolean',
        'price'         => 'integer',
        'validity_days' => 'integer',
        'sort_order'    => 'integer',
    ];

    public function customerName(): string
    {
        return $this->display_name ?: $this->groupname;
    }

    public function priceLabel(): ?string
    {
        if (! $this->show_price || ! $this->price) {
            return null;
        }

        return 'Rp'.number_format((float) $this->price, 0, ',', '.');
    }

    public function validityLabel(): ?string
    {
        return $this->validity_days ? $this->validity_days.' Hari' : null;
    }

    public static function customerNameMap(): array
    {
        return static::query()
            ->get(['groupname', 'display_name'])
            ->mapWithKeys(fn (self $p) => [$p->groupname => $p->customerName()])
            ->all();
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(PackageAttribute::class)->orderBy('sort_order');
    }
}
