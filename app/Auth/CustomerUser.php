<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class CustomerUser implements Authenticatable
{
    public function __construct(
        public string $username,
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $rememberToken = null,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    public function getAuthIdentifier(): string
    {
        return $this->username;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken($value): void
    {
        $this->rememberToken = $value !== null ? (string) $value : null;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
