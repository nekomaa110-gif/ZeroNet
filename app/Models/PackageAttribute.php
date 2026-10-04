<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageAttribute extends Model
{
    protected $fillable = ['package_id', 'attribute', 'op', 'value', 'target_table', 'sort_order'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
