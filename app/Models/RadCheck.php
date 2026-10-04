<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadCheck extends Model
{
    public $timestamps = false;

    protected $table = 'radcheck';

    protected $fillable = ['username', 'attribute', 'op', 'value'];
}
