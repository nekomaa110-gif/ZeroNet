<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\PerangkatDikenal;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const FIRST_LOCKOUT_AFTER = 3;

    private const BASE_SECONDS = 10;

    private const MAX_SECONDS = 3600;

    private const DECAY_SECONDS = 3600;

    private const USERNAME_MAX_FAILURES = 10;

    private const USERNAME_LOCK_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        $username = (string) $this->input('login');
        $password = (string) $this->input('password');

        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            event(new Failed('web', $user, ['username' => $username, 'password' => $password]));

            $this->registerFailedAttempt();

            throw ValidationException::withMessages([
                'login' => 'Username atau password salah.',
            ]);
        }

        if (! $user->is_active) {
            $this->registerFailedAttempt();

            throw ValidationException::withMessages([
                'login' => 'Akun Anda telah dinonaktifkan.',
            ]);
        }

        $this->clearRateLimiter();

        return $user;
    }

    public function ensureIsNotRateLimited(): void
    {
        $seconds = max($this->secondsRemaining(), $this->perangkatDikenal() ? 0 : $this->usernameSecondsRemaining());

        if ($seconds <= 0) {
            return;
        }

        event(new Lockout($this));

        $this->throwLockout($seconds);
    }

    protected function registerFailedAttempt(): void
    {
        if (! $this->perangkatDikenal()) {
            $this->registerUsernameFailure();
        }

        $level    = (int) Cache::get($this->levelKey(), 0);
        $attempts = (int) Cache::get($this->attemptsKey(), 0) + 1;

        $threshold = $level === 0 ? self::FIRST_LOCKOUT_AFTER : 1;

        if ($attempts < $threshold) {
            Cache::put($this->attemptsKey(), $attempts, self::DECAY_SECONDS);

            return;
        }

        $level++;
        $seconds = $this->lockoutSecondsFor($level);

        Cache::forget($this->attemptsKey());
        Cache::put($this->levelKey(), $level, max(self::DECAY_SECONDS, $seconds * 2));
        Cache::put($this->lockoutKey(), time() + $seconds, $seconds);

        event(new Lockout($this));

        $this->throwLockout($seconds);
    }

    protected function registerUsernameFailure(): void
    {
        $key = $this->usernameFailuresKey();

        Cache::add($key, 0, self::DECAY_SECONDS);
        $failures = (int) Cache::increment($key);

        if ($failures >= self::USERNAME_MAX_FAILURES) {
            Cache::forget($key);
            Cache::put($this->usernameLockoutKey(), time() + self::USERNAME_LOCK_SECONDS, self::USERNAME_LOCK_SECONDS);
        }
    }

    protected function perangkatDikenal(): bool
    {
        return PerangkatDikenal::dikenal($this, PerangkatDikenal::ADMIN, (string) $this->input('login'));
    }

    protected function usernameSecondsRemaining(): int
    {
        $until = (int) Cache::get($this->usernameLockoutKey(), 0);

        return max(0, $until - time());
    }

    protected function clearRateLimiter(): void
    {
        Cache::forget($this->usernameFailuresKey());
        Cache::forget($this->attemptsKey());
        Cache::forget($this->levelKey());
        Cache::forget($this->lockoutKey());
    }

    protected function lockoutSecondsFor(int $level): int
    {
        if ($level > 20) {
            return self::MAX_SECONDS;
        }

        return (int) min(self::BASE_SECONDS * (2 ** ($level - 1)), self::MAX_SECONDS);
    }

    protected function secondsRemaining(): int
    {
        $until = (int) Cache::get($this->lockoutKey(), 0);

        return max(0, $until - time());
    }

    protected function throwLockout(int $seconds): never
    {
        $this->session()->flash('lockout_seconds', $seconds);

        throw ValidationException::withMessages([
            'login' => 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . $this->humanizeSeconds($seconds) . '.',
        ]);
    }

    protected function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' detik';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes < 60
            ? $minutes . ' menit'
            : (int) ceil($minutes / 60) . ' jam';
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')) . '|' . $this->ip());
    }

    protected function attemptsKey(): string
    {
        return 'login:attempts:' . sha1($this->throttleKey());
    }

    protected function levelKey(): string
    {
        return 'login:level:' . sha1($this->throttleKey());
    }

    protected function lockoutKey(): string
    {
        return 'login:lockout:' . sha1($this->throttleKey());
    }

    protected function usernameKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')));
    }

    protected function usernameFailuresKey(): string
    {
        return 'login:user-failures:' . sha1($this->usernameKey());
    }

    protected function usernameLockoutKey(): string
    {
        return 'login:user-lockout:' . sha1($this->usernameKey());
    }
}
