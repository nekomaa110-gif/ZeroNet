<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadReply extends Model
{
    public $timestamps = false;

    protected $table = 'radreply';

    protected $fillable = ['username', 'attribute', 'op', 'value'];
}
