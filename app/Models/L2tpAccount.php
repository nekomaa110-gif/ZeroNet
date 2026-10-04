<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class L2tpAccount extends Model
{
    protected $fillable = ['username', 'secret', 'remote_ip', 'note', 'is_active', 'created_by'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
