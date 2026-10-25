<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPrice extends Model
{
    protected $fillable = ['username', 'price', 'updated_by'];

    protected $casts = [
        'price' => 'integer',
    ];
}
