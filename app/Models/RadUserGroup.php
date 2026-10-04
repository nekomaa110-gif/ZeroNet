<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadUserGroup extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'radusergroup';

    protected $fillable = ['username', 'groupname', 'priority'];
}
