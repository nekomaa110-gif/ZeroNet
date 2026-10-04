<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password'                  => 'hashed',
            'is_active'                 => 'boolean',
            'last_login_at'             => 'datetime',
            'two_factor_confirmed_at'   => 'datetime',
            'two_factor_secret'         => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    public function recoveryCodes(): array
    {
        if (empty($this->two_factor_recovery_codes)) {
            return [];
        }

        return json_decode($this->two_factor_recovery_codes, true) ?: [];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
