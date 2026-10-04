<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterLog;
use App\Models\User;
use App\Services\RouterLogService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterLogTest extends TestCase
{
    use RefreshDatabase;

    private const JAM = ['date' => 'sep/27/2026', 'time' => '13:00:00', 'gmt-offset' => '+07:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    private function router(string $slug = 'uji', int $port = 1): Router
    {
        return Router::create(['slug' => $slug, 'name' => strtoupper($slug), 'host' => '127.0.0.1', 'port' => $port, 'username' => 'x', 'password' => 'x']);
    }

    private function baris(int $id, string $time, string $topics, string $message): array
    {
        return ['.id' => '*' . strtoupper(dechex($id)), 'time' => $time, 'topics' => $topics, 'message' => $message];
    }

    public function test_pesan_hotspot_diurai_jadi_user_ip_dan_kejadian(): void
    {
        $this->assertSame(['pengguna' => '5K10001', 'ip' => '10.10.10.200', 'kejadian' => 'masuk', 'pesan' => 'logged in'], RouterLogService::urai('->: 5K10001 (10.10.10.200): logged in'));
        $this->assertSame(['pengguna' => '5C8528', 'ip' => '10.10.10.31', 'kejadian' => 'masuk', 'pesan' => 'logged in'], RouterLogService::urai('5C8528 (10.10.10.31): logged in'));
        $this->assertSame('gagal', RouterLogService::urai('5Kab2 (10.10.10.4): login failed: Username atau password salah')['kejadian']);
        $this->assertSame('keluar', RouterLogService::urai('->: Budi (10.10.10.9): logged out: keepalive timeout')['kejadian']);
        $this->assertSame('logged out: keepalive timeout', RouterLogService::urai('->: Budi (10.10.10.9): logged out: keepalive timeout')['pesan']);
        $this->assertSame('gagal', RouterLogService::urai('->: x1 (10.10.10.5): login failed: your uptime limit is reached')['kejadian']);
        $this->assertSame('mencoba', RouterLogService::urai('->: 5Kab (10.10.10.7): trying to log in by mac-cookie')['kejadian']);
        $this->assertSame(['pengguna' => null, 'ip' => '10.10.10.8', 'kejadian' => 'mencoba', 'pesan' => 'trying to log in by http-pap'], RouterLogService::urai('->: (10.10.10.8): trying to log in by http-pap'));
        $this->assertSame(['pengguna' => null, 'ip' => null, 'kejadian' => 'lain', 'pesan' => 'hotspot1: address pool exhausted'], RouterLogService::urai('hotspot1: address pool exhausted'));
    }

    public function test_waktu_log_router_ros6_dan_ros7_termasuk_lewat_tengah_malam_dan_tahun(): void
    {
        $s    = app(RouterLogService::class);
        $zona = new DateTimeZone('+07:00');
        $kini = new DateTimeImmutable('2026-09-27 00:03:00', $zona);

        $this->assertSame('2026-09-27 00:01:00', $s->waktu('00:01:00', $kini, $zona)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-26 23:58:00', $s->waktu('23:58:00', $kini, $zona)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-25 21:10:05', $s->waktu('sep/25 21:10:05', $kini, $zona)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-25 21:10:05', $s->waktu('2026-09-25 21:10:05', $kini, $zona)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-25 21:10:05', $s->waktu('09-25 21:10:05', $kini, $zona)->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:00:00', $s->waktu('dec/31 23:00:00', new DateTimeImmutable('2026-01-02 08:00:00', $zona), $zona)->format('Y-m-d H:i:s'));
        $this->assertNull($s->waktu('kemarin', $kini, $zona));
    }

    public function test_hanya_topik_hotspot_disimpan_tanpa_dobel_dan_celah_serta_reboot_terdeteksi(): void
    {
        $r = $this->router();
        $s = app(RouterLogService::class);

        $awal = [
            $this->baris(0x100, '12:58:00', 'hotspot,account,info,debug', '->: a1 (10.10.10.2): logged in'),
            $this->baris(0x101, '12:58:10', 'system,info,account', 'user zeropanel logged in from 10.255.255.1 via api'),
            $this->baris(0x102, '12:59:00', 'hotspot,info,debug', '->: a2 (10.10.10.3): trying to log in by mac-cookie'),
        ];
        $this->assertSame(['baru' => 2, 'celah' => false, 'reboot' => false], $s->simpan($r, $awal, self::JAM));

        $lanjut = [...array_slice($awal, 1), $this->baris(0x103, '12:59:30', 'hotspot,info,debug', '->: a2 (10.10.10.3): logged in')];
        $this->assertSame(['baru' => 1, 'celah' => false, 'reboot' => false], $s->simpan($r, $lanjut, self::JAM));

        $loncat = [$this->baris(0x110, '13:10:00', 'hotspot,info,debug', '->: a3 (10.10.10.4): logged in')];
        $this->assertSame(['baru' => 1, 'celah' => true, 'reboot' => false], $s->simpan($r, $loncat, ['time' => '13:10:30'] + self::JAM));

        $boot = [$this->baris(0x1, '13:20:00', 'hotspot,info,debug', '->: a4 (10.10.10.5): logged in')];
        $this->assertSame(['baru' => 1, 'celah' => false, 'reboot' => true], $s->simpan($r, $boot, ['time' => '13:21:00'] + self::JAM));

        $lama = [$this->baris(0x2, '2026-09-10 08:00:00', 'hotspot,info,debug', '->: kuno (10.10.10.6): logged in')];
        $this->assertSame(['baru' => 0, 'celah' => false, 'reboot' => false], $s->simpan($r, $lama, ['time' => '13:22:00'] + self::JAM));

        $this->assertSame(['a1', 'a2', 'a2', 'a3', 'a4'], RouterLog::orderBy('waktu')->pluck('pengguna')->all());
        $this->assertSame('2026-09-27 12:58:00', RouterLog::where('pengguna', 'a1')->first()->waktu->format('Y-m-d H:i:s'));
    }

    public function test_log_lebih_tua_dari_tujuh_hari_dibersihkan(): void
    {
        $r = $this->router();
        foreach ([8, 6] as $i => $hari) {
            RouterLog::create(['router_id' => $r->id, 'waktu' => now()->subDays($hari), 'topik' => 'hotspot', 'kejadian' => 'masuk', 'pesan' => 'logged in', 'hash' => sha1((string) $i)]);
        }

        $this->artisan('router:log-bersihkan')->expectsOutputToContain('1 baris')->assertSuccessful();
        $this->assertSame(1, RouterLog::count());
    }

    public function test_poller_membaca_log_router_ke_database(): void
    {
        $palsu = RouterPalsu::mulai([], [], ['/log' => [
            ['time' => '12:50:00', 'topics' => 'hotspot,account,info,debug', 'message' => '->: 5Kx1 (10.10.10.9): logged in'],
            ['time' => '12:51:00', 'topics' => 'dhcp,info', 'message' => 'dhcp1 assigned 10.10.10.9'],
        ]], ['date' => 'sep/27/2026', 'time' => '13:00:00']);

        try {
            Router::create(['slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $palsu->port, 'username' => 'api', 'password' => 'rahasia']);
            $this->artisan('mikrotik:poll', ['--once' => true, '--router' => 'uji'])->assertSuccessful();
        } finally {
            $palsu->berhenti();
        }

        $this->assertSame([['5Kx1', 'masuk', '10.10.10.9']], RouterLog::get(['pengguna', 'kejadian', 'ip'])->map(fn ($l) => array_values($l->toArray()))->all());
    }

    public function test_halaman_log_router_bisa_disaring_dan_menandai_poller(): void
    {
        $a = $this->router('div-a');
        $b = $this->router('div-b');
        foreach ([[$a, 'budi', 'masuk'], [$a, 'budi', 'gagal'], [$b, 'sari', 'masuk']] as $i => [$r, $u, $k]) {
            RouterLog::create(['router_id' => $r->id, 'waktu' => now()->subMinutes(10 - $i), 'topik' => 'hotspot', 'pengguna' => $u, 'ip' => '10.10.10.' . $i, 'kejadian' => $k, 'pesan' => $k === 'gagal' ? 'login failed: invalid password' : 'logged in', 'hash' => sha1((string) $i)]);
        }
        $this->actingAs(User::create([
            'name' => 'op', 'username' => 'opuji', 'email' => 'op@example.test', 'password' => Hash::make('x'),
            'role' => 'operator', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));

        $this->get(route('hotspot-logs.index', ['sumber' => 'router', 'router' => 'div-a', 'kejadian' => 'gagal']))
            ->assertOk()->assertSee('login failed: invalid password')->assertDontSee('sari');
        $this->get(route('hotspot-logs.index', ['sumber' => 'router', 'cari' => 'sar']))
            ->assertOk()->assertSee('sari')->assertDontSee('budi');

        $this->getJson(route('hotspot-logs.tonton'))->assertOk()->assertJson(['terbaru' => RouterLog::max('id')]);
        $this->assertTrue(Cache::has('mt.watch.log.div-a'));
    }
}
