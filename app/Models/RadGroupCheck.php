<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadGroupCheck extends Model
{
    public $timestamps = false;

    protected $table = 'radgroupcheck';

    protected $fillable = ['groupname', 'attribute', 'op', 'value'];
}
