<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PerangkatDikenal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginPerangkatDikenalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Uji', 'username' => 'adminuji', 'email' => 'adminuji@example.test',
            'password' => Hash::make('benar-sekali'), 'role' => 'operator', 'is_active' => true,
        ]);
    }

    private function login(string $ip, string $password, ?string $cookie = null)
    {
        $req = $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        if ($cookie !== null) {
            $req = $req->withCookie(PerangkatDikenal::ADMIN, $cookie);
        }

        return $req->post(route('login'), ['login' => 'adminuji', 'password' => $password]);
    }

    private function kunciDariBanyakIp(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->login("203.0.113.{$i}", 'salah');
            $this->flushSession();
        }
    }

    public function test_perangkat_dikenal_tetap_bisa_login_saat_username_dikunci_penyerang(): void
    {
        $this->admin();

        $cookie = $this->login('198.51.100.7', 'benar-sekali')->getCookie(PerangkatDikenal::ADMIN)?->getValue();
        $this->assertNotEmpty($cookie);
        auth()->logout();
        $this->flushSession();

        $this->kunciDariBanyakIp();

        $this->login('198.51.100.99', 'benar-sekali')->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->flushSession();

        $this->login('198.51.100.99', 'benar-sekali', $cookie)->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_gagal_dari_perangkat_dikenal_tidak_mengunci_username(): void
    {
        $this->admin();

        $cookie = $this->login('198.51.100.7', 'benar-sekali')->getCookie(PerangkatDikenal::ADMIN)?->getValue();
        auth()->logout();
        $this->flushSession();

        for ($i = 1; $i <= 10; $i++) {
            $this->login("198.51.100.{$i}", 'salah', $cookie);
            $this->flushSession();
        }

        $this->login('198.51.100.200', 'benar-sekali')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
