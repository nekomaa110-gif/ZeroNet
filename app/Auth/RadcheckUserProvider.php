<?php

namespace App\Auth;

use App\Models\CustomerContact;
use App\Models\CustomerRememberToken;
use App\Models\RadCheck;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

class RadcheckUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        $username = (string) $identifier;

        $row = RadCheck::whereRaw('BINARY username = ?', [$username])
            ->where('attribute', 'Cleartext-Password')
            ->first();

        if (!$row) {
            return null;
        }

        return $this->buildUser($row->username);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        if (!$token) {
            return null;
        }

        $username = (string) $identifier;

        $row = CustomerRememberToken::where('username', $username)->first();

        if (!$row || !hash_equals((string) $row->token, (string) $token)) {
            return null;
        }

        $fingerprint = $this->passwordFingerprint($username);

        if ($fingerprint === null
            || $row->password_fingerprint === null
            || !hash_equals((string) $row->password_fingerprint, $fingerprint)) {
            $row->delete();

            return null;
        }

        return $this->retrieveById($username);
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        $username = (string) $user->getAuthIdentifier();

        CustomerRememberToken::updateOrCreate(
            ['username' => $username],
            [
                'token' => (string) $token,
                'password_fingerprint' => $this->passwordFingerprint($username),
            ],
        );
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? null;
        if (!$username) {
            return null;
        }

        $row = RadCheck::whereRaw('BINARY username = ?', [$username])
            ->where('attribute', 'Cleartext-Password')
            ->first();

        if (!$row) {
            return null;
        }

        return $this->buildUser($row->username);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (!$password) {
            return false;
        }

        $row = RadCheck::whereRaw('BINARY username = ?', [$user->getAuthIdentifier()])
            ->where('attribute', 'Cleartext-Password')
            ->first();

        if (!$row) {
            return false;
        }

        return hash_equals((string) $row->value, (string) $password);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}

    private function passwordFingerprint(string $username): ?string
    {
        $value = RadCheck::whereRaw('BINARY username = ?', [$username])
            ->where('attribute', 'Cleartext-Password')
            ->value('value');

        return $value === null ? null : hash('sha256', (string) $value);
    }

    private function buildUser(string $username): CustomerUser
    {
        $contact = CustomerContact::where('username', $username)->first();
        $remember = CustomerRememberToken::where('username', $username)->first();

        return new CustomerUser(
            username: $username,
            name: $contact?->name,
            phone: $contact?->phone,
            rememberToken: $remember?->token,
        );
    }
}
