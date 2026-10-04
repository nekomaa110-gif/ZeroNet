<?php

namespace App\Console\Commands;

use App\Services\VoucherLookupService;
use Illuminate\Console\Command;

class VoucherCek extends Command
{
    protected $signature = 'voucher:cek
        {kode? : Username voucher, mis. 5Kqz27 (huruf besar/kecil bebas)}
        {--pass= : Cari lewat password, dipakai kalau username di kartu tidak terbaca}
        {--router= : Batasi ke slug router tertentu, dipisah koma}
        {--log : Ikut ambil /log router (lebih lambat, dipakai saat menelusuri kasus rumit)}';

    protected $description = 'Telusuri voucher hotspot di semua router: status, bukti pemakaian, IP/MAC/host, dan riwayat perangkatnya';

    public function handle(VoucherLookupService $service): int
    {
        $kode = trim((string) $this->argument('kode'));
        $pass = trim((string) $this->option('pass'));

        if ($kode === '' && $pass === '') {
            $this->error('Isi minimal salah satu: kode voucher, atau --pass=1234 kalau username di kartu tidak terbaca.');

            return self::FAILURE;
        }

        $slugs = $this->option('router')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('router')))))
            : [];

        $this->judul($kode !== '' ? "CEK KODE  {$kode}" : "CARI PASSWORD  {$pass}");

        $r = $service->cek($kode, $pass, $slugs, (bool) $this->option('log'));

        foreach ($r['gagal'] as $g) {
            $this->line("  <fg=yellow>[!] {$g['nama']} ({$g['host']}) tidak bisa dihubungi: {$g['pesan']}</>");
            $this->newLine();
        }

        if ($r['mode'] === 'password') {
            return $this->cetakPencarianPassword($r);
        }

        foreach ($r['hasil'] as $baris) {
            $this->cetakRouter($baris);
        }

        if ($r['radius']) {
            $this->cetakRadius($r['radius']);
        }

        if (! $r['ketemu']) {
            $this->vonis('TIDAK PERNAH ADA', 'red');
            $this->line('  Kode ini tidak ada di daftar user, catatan penjualan, maupun radcheck.');
            $this->line('  Kemungkinan: salah baca kartu, atau kartunya dari lokasi/router lain yang belum ikut dicek.');
            $this->newLine();
            $this->cetakMirip($r['mirip']);

            return self::SUCCESS;
        }

        if ($r['riwayat']) {
            $this->cetakRiwayat($r['riwayat']);
        }

        return self::SUCCESS;
    }

    private function cetakRouter(array $b): void
    {
        $rt = $b['router'];
        $this->line("  <fg=cyan;options=bold>ROUTER  {$rt['nama']}</>  <fg=gray>({$rt['host']} · {$rt['slug']} · jam router {$b['jam_teks']})</>");
        $this->newLine();

        $warna = ['ok' => 'green', 'warn' => 'yellow', 'err' => 'red'][$b['vonis']['level']] ?? 'white';
        $this->vonis($b['vonis']['teks'], $warna);

        foreach ($b['vonis']['catatan'] as $c) {
            $this->line("  {$c}");
        }
        if ($b['vonis']['catatan']) {
            $this->newLine();
        }

        if ($u = $b['user']) {
            $this->sub('DATA DI ROUTER');
            $this->baris('Username', $u['nama']);
            $this->baris('Password', $u['password'] . match ($u['pass_cocok']) {
                true    => '  <fg=green>✓ cocok dengan kartu</>',
                false   => '  <fg=red>✗ BEDA dari kartu</>',
                default => '',
            });
            $this->baris('Router / divisi', "{$rt['nama']}  ({$rt['host']})");
            $this->baris('Hotspot server', $u['server']);
            $this->baris('Profile', $u['profile'] . ($u['profil_info'] !== '' ? "   <fg=gray>({$u['profil_info']})</>" : ''));
            $this->baris('Kuota waktu', $u['kuota_teks']);
            $this->baris('Pemakaian data', $u['data_teks']);

            if ($u['mac_kunci'] !== '') {
                $this->baris('Terkunci ke MAC', $u['mac_kunci']);
            }

            $this->baris('Comment', $u['comment'] !== '' ? $u['comment'] : '(kosong)');
            $this->newLine();

            $this->sub('MASA AKTIF');
            $this->baris('Batas hangus', $b['masa']['exp_teks']);
            if ($b['masa']['sisa_teks'] !== '') {
                $this->baris($b['masa']['exp'] ? 'Sisa masa aktif' : 'Artinya', $b['masa']['sisa_teks']);
            }
            $this->newLine();
        }

        if ($rec = $b['record']) {
            $this->sub('BUKTI PEMAKAIAN (catatan penjualan mikhmon)');
            $this->baris('Login pertama', $rec['waktu_teks']);
            $this->baris('IP saat login', $rec['ip'] !== '' ? $rec['ip'] : '-');
            $this->baris('MAC perangkat', $rec['mac'] !== '' ? "<options=bold>{$rec['mac']}</>" : '-');
            $this->baris('Harga', $rec['harga_teks']);
            $this->baris('Masa aktif dijual', $rec['masa'] !== '' ? $rec['masa'] : '-');
            $this->baris('Profile saat jual', $rec['profile'] !== '' ? $rec['profile'] : '-');
            $this->baris('Batch asal', $rec['batch'] !== '' ? $rec['batch'] : '-');

            if (! $b['user']) {
                $this->baris('Kesimpulan', '<fg=red>kartu ini sudah laku dan sudah dipakai, komplain bisa ditolak</>');
            }

            if (count($b['records']) > 1) {
                $this->newLine();
                $this->line('  <fg=yellow>Ada ' . count($b['records']) . ' catatan untuk kode ini</>, pernah dibuat ulang / dipakai lebih dari sekali:');
                foreach ($b['records'] as $r2) {
                    $this->line(sprintf('    · %-22s IP %-15s MAC %-17s batch %s', $r2['waktu_teks'], $r2['ip'], $r2['mac'], $r2['batch']));
                }
            }
            $this->newLine();
        } elseif ($b['user']) {
            $this->sub('CATATAN PENJUALAN');
            $this->line('  <fg=gray>Belum ada (kode ini memang belum pernah dipakai login).</>');
            $this->newLine();
        }

        $this->sub('SESI AKTIF SEKARANG');
        if (! $b['sesi']) {
            $this->line('  <fg=gray>Tidak ada (kode ini sedang tidak login).</>');
            $this->newLine();
        }
        foreach ($b['sesi'] as $a) {
            $this->baris('IP', $a['address'] ?? '-');
            $this->baris('MAC', $a['mac-address'] ?? '-');
            $this->baris('Server', $a['server'] ?? '-');
            $this->baris('Lama sesi', $a['uptime'] ?? '-');
            $this->baris('Sisa jatah sesi', $a['session-time-left'] ?? '(tanpa batas)');
            $this->baris('Idle', $a['idle-time'] ?? '-');
            $this->baris('Login via', $a['login-by'] ?? '-');
            $this->newLine();
        }

        if ($b['mac'] !== '') {
            $this->sub("PERANGKAT / HOST  ({$b['mac']})");
            if (! $b['hosts']) {
                $this->line('  <fg=gray>Perangkat ini sudah tidak terdaftar di /ip hotspot host (sudah pergi dari jaringan).</>');
            }
            foreach ($b['hosts'] as $h) {
                $this->baris('IP didapat', ($h['address'] ?? '-') . (($h['to-address'] ?? '') !== '' ? '  →  ' . $h['to-address'] : ''));
                $this->baris('Server', $h['server'] ?? '-');
                $this->baris('Lama nyantol', $h['uptime'] ?? '-');
                $this->baris('Idle', $h['idle-time'] ?? '-');
                $this->baris('Status', ($h['authorized'] ?? '') === 'true' ? 'sudah login' : 'baru nyantol, belum login');
                $this->baris('Trafik host', $h['trafik']);
            }
            foreach ($b['cookies'] as $c) {
                $this->baris('Mac-cookie', 'ada, kedaluwarsa dalam ' . ($c['expires-in'] ?? '?') . '  <fg=gray>(bisa login otomatis tanpa ketik kode)</>');
            }
            $this->newLine();
        }

        if ($b['log']) {
            $this->sub('LOG ROUTER');
            foreach ($b['log'] as $l) {
                $this->line(sprintf('  %-14s %s', $l['time'] ?? '', $l['message'] ?? ''));
            }
            $this->newLine();
        }
    }

    private function cetakRadius(array $r): void
    {
        $warna = $r['vonis']['level'] === 'ok' ? 'green' : 'red';
        $this->vonis($r['vonis']['teks'], $warna);
        $this->line('  <fg=gray>Ini user RADIUS, bukan voucher lokal. Kelola lewat panel /admin, jangan lewat Mikhmon.</>');
        $this->newLine();

        $this->sub('DATA RADIUS (radcheck)');
        $this->baris('Username', $r['username']);
        $this->baris('Paket / group', $r['group']);
        $this->baris('Expiration', $r['exp_teks']);
        $this->baris('Sisa masa aktif', $r['sisa_teks']);
        $this->baris('Status blokir', $r['diblokir'] ? '<fg=red>diblokir (baris Auth-Type := Reject)</>' : 'tidak diblokir');
        $this->newLine();

        if ($s = $r['sesi']) {
            $this->sub('SESI TERAKHIR (radacct)');
            $this->baris('Mulai', (string) $s->acctstarttime);
            $this->baris('Berhenti', $s->acctstoptime ? (string) $s->acctstoptime : '<fg=green>masih berjalan</>');
            $this->baris('IP', (string) $s->framedipaddress);
            $this->baris('MAC', (string) $s->callingstationid);
            $this->baris('NAS / router', (string) $s->nasipaddress);
            $this->baris('Alasan putus', (string) ($s->acctterminatecause ?: '-'));
            $this->newLine();
        }

        foreach ($r['hotspot'] as $a) {
            $this->sub("SESI HOTSPOT DI {$a['slug']}");
            $this->baris('IP', $a['address'] ?? '-');
            $this->baris('MAC', $a['mac-address'] ?? '-');
            $this->baris('Lama sesi', $a['uptime'] ?? '-');
            $this->baris('Idle', $a['idle-time'] ?? '-');
            $this->baris('Login via', $a['login-by'] ?? '-');
            $this->newLine();
        }
    }

    private function cetakRiwayat(array $riwayat): void
    {
        $this->judul("RIWAYAT PERANGKAT  {$riwayat['mac']}   ({$riwayat['router']})");
        $this->line('  Perangkat ini tercatat memakai <options=bold>' . $riwayat['total'] . ' voucher</>:');
        $this->newLine();

        foreach ($riwayat['items'] as $r) {
            $this->line(sprintf(
                '  %s %-22s %-10s %-11s IP %-15s %s',
                $r['ini'] ? '<fg=yellow>›</>' : ' ',
                $r['waktu_teks'], $r['user'], $r['harga_teks'], $r['ip'], $r['batch']
            ));
        }

        if ($riwayat['total'] > 1) {
            $this->newLine();
            $this->line('  <fg=gray>Banyak voucher dari satu perangkat itu wajar untuk pelanggan langganan.</>');
            $this->line('  <fg=gray>Curigai kalau kartu fisiknya ternyata masih utuh di tempat jualan, berarti kodenya kebaca orang.</>');
        }
        $this->newLine();
    }

    private function cetakPencarianPassword(array $r): int
    {
        if (! $r['cocok']) {
            $this->line('  <fg=red>Tidak ada user dengan password itu.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('  ' . count($r['cocok']) . " user memakai password {$r['pass']}:");
        $this->newLine();

        foreach ($r['cocok'] as $u) {
            $this->line(sprintf(
                '    %-10s %-9s profile=%-9s limit=%-6s uptime=%-10s comment=%s',
                $u['nama'], $u['slug'], $u['profile'], $u['limit'], $u['uptime'], $u['comment'] ?: '(kosong)'
            ));
        }

        $this->newLine();
        $this->line('  <fg=gray>Cocokkan username-nya dengan kartu, lalu jalankan lagi: php artisan voucher:cek <username></>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function cetakMirip(array $mirip): void
    {
        if (! $mirip) {
            return;
        }

        $this->line('  <fg=yellow>Nama yang beda satu huruf</>, cocokkan password-nya dengan kartu:');
        foreach ($mirip as $m) {
            $this->line(sprintf(
                '    %-10s pass=%-8s %-9s profile=%-9s uptime=%-10s %s',
                $m['nama'], $m['pass'], $m['slug'], $m['profile'], $m['uptime'], $m['comment']
            ));
        }
        $this->newLine();
    }

    private function judul(string $teks): void
    {
        $this->newLine();
        $this->line('<options=bold>' . str_repeat('═', 74) . '</>');
        $this->line("<options=bold>  {$teks}</>");
        $this->line('<options=bold>' . str_repeat('═', 74) . '</>');
        $this->newLine();
    }

    private function sub(string $teks): void
    {
        $this->line("  <fg=cyan>── {$teks} " . str_repeat('─', max(3, 66 - mb_strlen($teks))) . '</>');
    }

    private function vonis(string $teks, string $warna): void
    {
        $this->line("  <fg={$warna};options=bold>▌ {$teks}</>");
        $this->newLine();
    }

    private function baris(string $label, string $isi): void
    {
        $this->line(sprintf('  %-18s : %s', $label, $isi));
    }
}
