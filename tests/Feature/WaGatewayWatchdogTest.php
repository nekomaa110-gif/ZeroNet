<?php

namespace Tests\Feature;

use App\Console\Commands\WaGatewayWatchdog;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class WaGatewayWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private function gatewayPutus(): void
    {
        User::create([
            'name' => 'Admin', 'username' => 'admin1', 'email' => 'admin1@example.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'is_active' => true,
        ]);

        $wa = Mockery::mock(WhatsAppService::class);
        $wa->shouldReceive('status')->andReturn(['status' => 'close']);
        $this->app->instance(WhatsAppService::class, $wa);

        Cache::forever(WaGatewayWatchdog::DOWN_KEY, ['since' => time() - 1000]);
    }

    public function test_mailer_log_tidak_dilaporkan_sebagai_email_terkirim(): void
    {
        $this->gatewayPutus();
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->artisan('wa:watchdog')
            ->expectsOutputToContain('email tidak dikirim karena MAIL_MAILER=log')
            ->doesntExpectOutputToContain('email peringatan dikirim')
            ->assertSuccessful();

        Mail::assertNothingOutgoing();
    }

    public function test_mailer_smtp_mengirim_email_ke_admin(): void
    {
        $this->gatewayPutus();
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->artisan('wa:watchdog')
            ->expectsOutputToContain('email peringatan dikirim ke admin1@example.test')
            ->assertSuccessful();
    }
}
