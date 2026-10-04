<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\PembersihHtml;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TemplateCetakService
{
    public const MAKS_PANJANG = 60000;

    public const VARIABEL = [
        'kode'             => 'Username / kode voucher',
        'password'         => 'Password',
        'harga'            => 'Harga, mis. Rp 5.000 (kosong kalau 0)',
        'masa'             => 'Masa aktif, mis. 1 hari',
        'masa_asli'        => 'Masa aktif apa adanya, mis. 1d',
        'batas_waktu'      => 'Jatah jam, mis. 4 jam',
        'batas_waktu_asli' => 'Jatah jam apa adanya, mis. 4h',
        'kuota'            => 'Kuota data, mis. 1 GB',
        'profil'           => 'Profil hotspot',
        'comment'          => 'Comment batch',
        'hotspot'          => 'Nama hotspot untuk login, mis. hotspot.lan',
        'login_url'        => 'Alamat login lengkap dengan username dan password',
        'qr'               => 'Kode QR ke alamat login',
        'nomor'            => 'Nomor urut kartu di lembar ini',
        'brand'            => 'Nama brand',
    ];

    private const SATUAN = ['w' => 'minggu', 'd' => 'hari', 'h' => 'jam', 'm' => 'menit', 's' => 'detik'];

    public function __construct(private PembersihHtml $pembersih) {}

    public function render(string $html, iterable $vouchers, VoucherBatch $batch): array
    {
        [$gaya, $isi] = $this->pisahGaya($html);
        $pakaiQr      = str_contains($isi, '{{qr}}');
        $varian       = [];
        $kartu        = [];
        $nomor        = 0;

        foreach ($vouchers as $v) {
            $nilai = $this->nilai($v, $batch, ++$nomor, $pakaiQr);
            $mode  = $v->username === $v->password ? 'vc' : 'up';
            $aktif = [$mode => true] + array_map(fn ($x) => $x !== '', $nilai);
            $kunci = $mode . '|' . implode(',', array_keys(array_filter($aktif)));

            $varian[$kunci] ??= $this->pembersih->bersihkan($this->bagian($isi, $aktif));

            $kartu[] = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($nilai) {
                if ($m[1] === 'qr') {
                    return $nilai['qr'];
                }

                return e($nilai[$m[1]] ?? '');
            }, $varian[$kunci]);
        }

        return ['gaya' => $gaya, 'kartu' => $kartu];
    }

    public function periksa(string $html): array
    {
        [, $isi] = $this->pisahGaya($html);
        $dibuang = [];

        foreach (['vc', 'up'] as $mode) {
            $this->pembersih->bersihkan($this->bagian($isi, [$mode => true] + array_fill_keys(array_keys(self::VARIABEL), true)));
            $dibuang = [...$dibuang, ...$this->pembersih->dibuang()];
        }

        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $isi, $m);
        $asing = array_values(array_diff(array_unique($m[1]), array_keys(self::VARIABEL)));

        return ['dibuang' => array_values(array_unique($dibuang)), 'asing' => $asing];
    }

    public static function csp(string $nonce): string
    {
        return "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; script-src 'nonce-{$nonce}'; connect-src 'self'; "
            . "form-action 'none'; base-uri 'none'; frame-ancestors 'self'";
    }

    public static function durasi(?string $teks): string
    {
        $teks = trim((string) $teks);
        if ($teks === '' || preg_match('/^0+[wdhms]?$/', $teks)) {
            return '';
        }

        preg_match_all('/(\d+)\s*([wdhms])/i', $teks, $m, PREG_SET_ORDER);
        if (! $m) {
            return $teks;
        }

        return implode(' ', array_map(fn ($x) => (int) $x[1] . ' ' . self::SATUAN[strtolower($x[2])], $m));
    }

    public static function rupiah(?int $n): string
    {
        return $n ? 'Rp ' . number_format($n, 0, ',', '.') : '';
    }

    public static function kuota(?int $byte): string
    {
        if (! $byte) {
            return '';
        }

        $satuan = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i      = min(count($satuan) - 1, (int) floor(log($byte, 1024)));

        return rtrim(rtrim(number_format($byte / (1024 ** $i), 2, ',', '.'), '0'), ',') . ' ' . $satuan[$i];
    }

    private function nilai(Voucher $v, VoucherBatch $batch, int $nomor, bool $pakaiQr): array
    {
        $hotspot = (string) config('voucher.card.login');
        $login   = 'http://' . $hotspot . '/login?' . http_build_query(['username' => $v->username, 'password' => $v->password]);

        return [
            'kode'             => (string) $v->username,
            'password'         => (string) $v->password,
            'harga'            => self::rupiah($v->price ?? $batch->price),
            'masa'             => self::durasi($batch->validity),
            'masa_asli'        => (string) $batch->validity,
            'batas_waktu'      => self::durasi($v->limit_uptime ?? $batch->limit_uptime),
            'batas_waktu_asli' => (string) ($v->limit_uptime ?? $batch->limit_uptime),
            'kuota'            => self::kuota($v->limit_bytes ?? $batch->limit_bytes),
            'profil'           => (string) ($v->profile ?? $batch->profile),
            'comment'          => (string) ($v->comment ?? $batch->code),
            'hotspot'          => $hotspot,
            'login_url'        => $login,
            'qr'               => $pakaiQr ? $this->qr($login) : '',
            'nomor'            => (string) $nomor,
            'brand'            => (string) config('voucher.card.brand'),
        ];
    }

    private function bagian(string $isi, array $aktif): string
    {
        return preg_replace_callback('/\{\{#([a-z_]+)\}\}(.*?)\{\{\/\1\}\}/s', fn ($m) => ! empty($aktif[$m[1]]) ? $m[2] : '', $isi);
    }

    private function pisahGaya(string $html): array
    {
        $gaya = [];
        $isi  = preg_replace_callback('#<style\b[^>]*>(.*?)</style\s*>#is', function ($m) use (&$gaya) {
            $gaya[] = PembersihHtml::bersihkanCss($m[1]);

            return '';
        }, $html);

        return [implode("\n", array_filter($gaya)), $isi];
    }

    private function qr(string $teks): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(88, 0), new SvgImageBackEnd())))->writeString($teks);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}
