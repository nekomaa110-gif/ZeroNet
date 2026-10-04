<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadAcct extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'radacct';
    protected $primaryKey = 'radacctid';

    protected $fillable = [];
}
