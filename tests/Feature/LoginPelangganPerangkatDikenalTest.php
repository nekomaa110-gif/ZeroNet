<?php

namespace Tests\Feature;

use App\Models\RadCheck;
use App\Support\PerangkatDikenal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPelangganPerangkatDikenalTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $ip, string $password, ?string $cookie = null)
    {
        $req = $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        if ($cookie !== null) {
            $req = $req->withCookie(PerangkatDikenal::PELANGGAN, $cookie);
        }

        return $req->post(route('customer.login.attempt'), ['username' => 'pelangganuji', 'password' => $password]);
    }

    public function test_perangkat_pelanggan_dikenal_lolos_kunci_username(): void
    {
        RadCheck::create(['username' => 'pelangganuji', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'sandi-benar']);

        $cookie = $this->login('198.51.100.7', 'sandi-benar')->getCookie(PerangkatDikenal::PELANGGAN)?->getValue();
        $this->assertNotEmpty($cookie);
        auth('customer')->logout();
        $this->flushSession();

        for ($i = 1; $i <= 20; $i++) {
            $this->login("203.0.113.{$i}", 'salah');
            $this->flushSession();
        }

        $this->login('198.51.100.99', 'sandi-benar')->assertSessionHasErrors('throttle');
        $this->assertGuest('customer');
        $this->flushSession();

        $this->login('198.51.100.99', 'sandi-benar', $cookie)->assertSessionHasNoErrors();
        $this->assertAuthenticated('customer');
    }
}
