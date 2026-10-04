<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = ['key', 'body', 'updated_by'];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
