<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerRememberToken extends Model
{
    protected $fillable = ['username', 'token', 'password_fingerprint'];

    protected $hidden = ['token', 'password_fingerprint'];
}
