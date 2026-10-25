<?php

namespace App\Services;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherSale;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class CekRentangService
{
    public const MAKS = 1000;

    private const STATUS = [
        'ready'    => ['Belum dipakai', '', 'belum'],
        'active'   => ['Aktif', 'ok', 'aktif'],
        'expired'  => ['Habis', 'warn', 'habis'],
        'disabled' => ['Dinonaktifkan', 'err', 'habis'],
        'missing'  => ['Hilang dari router', 'err', 'bermasalah'],
    ];

    private const KOSONG = [
        'status'           => 'ok',
        'pesan'            => null,
        'ujung'            => null,
        'pilihan'          => [],
        'batch'            => null,
        'bernomor'         => false,
        'ringkas'          => ['total' => 0, 'belum' => 0, 'aktif' => 0, 'habis' => 0, 'dihapus' => 0, 'bermasalah' => 0],
        'baris'            => [],
        'peringatan'       => [],
        'peringatan_ujung' => [],
    ];

    public function __construct(
        private PencariKartuService $pencari,
        private VoucherRouterService $routers,
        private VoucherService $vouchers,
        private VoucherLookupService $lookup,
    ) {}

    public function rentang(array $dari, array $sampai, ?string $batchKode, ?string $routerSlug): array
    {
        $ujung           = [];
        $peringatanUjung = [];

        foreach (['dari' => [$dari, 'awal', 'Awal'], 'sampai' => [$sampai, 'akhir', 'Akhir']] as $kunci => [$isi, $nama, $awalan]) {
            $h = $this->pencari->cari($isi, $batchKode, $routerSlug);

            if ($h['status'] === 'galat') {
                return $this->galat($h['pesan']);
            }

            if ($h['status'] === 'tidak_ada') {
                return $this->galat("Kode {$nama} tidak ditemukan di panel maupun riwayat.");
            }

            if ($h['status'] === 'ambigu') {
                return array_replace(self::KOSONG, ['status' => 'ambigu', 'ujung' => $kunci, 'pilihan' => $h['pilihan']]);
            }

            $ujung[$kunci] = $h['kartu'];
            foreach ($h['peringatan'] as $p) {
                $peringatanUjung[] = "{$awalan}: {$p}";
            }
        }

        [$a, $b] = [$ujung['dari'], $ujung['sampai']];

        if ($a['batch_id'] !== $b['batch_id']) {
            return $this->galat("Kode awal dan akhir ada di batch berbeda ({$a['batch_code']} dan {$b['batch_code']}).");
        }

        $batchId  = (int) $a['batch_id'];
        $bernomor = $this->pencari->batchBernomor($batchId);
        $kunci    = fn (array $k) => $bernomor ? (int) $k['nomor'] : (int) $k['voucher_id'];
        $bawah    = min($kunci($a), $kunci($b));
        $atas     = max($kunci($a), $kunci($b));

        $hidup = Voucher::with('batch:id,validity')->where('batch_id', $batchId)->whereBetween($bernomor ? 'nomor' : 'id', [$bawah, $atas]);
        $hapus = DeletedVoucher::with('penghapus:id,name,username')->where('batch_id', $batchId)->whereBetween($bernomor ? 'nomor' : 'voucher_id', [$bawah, $atas]);

        $jumlah = (clone $hidup)->count() + (clone $hapus)->count();
        if ($jumlah > self::MAKS) {
            return $this->galat('Rentang maksimal ' . number_format(self::MAKS, 0, ',', '.') . ' kartu, yang diminta ' . number_format($jumlah, 0, ',', '.') . '.');
        }

        $hidup    = $hidup->get();
        $hapus    = $hapus->get();
        $batch    = VoucherBatch::with('creator:id,name,username')->find($batchId);
        $routerId = $batch ? $batch->router_id : ($hapus->first()?->router_id ?? $hidup->first()?->router_id);
        $router   = $routerId ? Router::find($routerId) : null;

        $peringatan = [];
        $baca       = null;

        if (! $router) {
            $peringatan[] = 'Router batch ini sudah tidak ada di panel, kolom Di router tidak tersedia.';
        } else {
            try {
                $baca = $this->routers->bacaCek($router);
            } catch (Throwable) {
                $peringatan[] = "Router {$router->name} tidak bisa dihubungi, kolom Di router tidak tersedia.";
            } finally {
                $this->routers->disconnect($router);
            }
        }

        $kodeBatch = $batch?->code ?? (string) ($hapus->first()?->batch_code ?? $a['batch_code']);
        $login     = $this->loginPertama($routerId, $kodeBatch, [...$hidup->pluck('username')->all(), ...$hapus->pluck('username')->all()]);

        $isi = [];
        foreach ($hidup as $v) {
            $isi[] = $this->barisHidup($v, $baca, $login, $bernomor);
        }
        foreach ($hapus as $d) {
            $isi[] = $this->barisHapus($d, $baca, $login, $bernomor);
        }
        usort($isi, fn ($x, $y) => $x['urut'] <=> $y['urut']);

        $ringkas  = self::KOSONG['ringkas'];
        $masihAda = 0;
        foreach ($isi as $r) {
            $ringkas[$r['kategori']]++;
            $masihAda += $r['masih'] ? 1 : 0;
        }
        $ringkas['total'] = count($isi);

        if ($masihAda > 0) {
            $peringatan[] = "{$masihAda} kartu terhapus masih ada di router.";
        }

        return array_replace(self::KOSONG, [
            'batch'            => $this->kepala($batch, $batchId, $hapus),
            'bernomor'         => $bernomor,
            'ringkas'          => $ringkas,
            'baris'            => array_map(fn ($r) => $r['baris'], $isi),
            'peringatan'       => $peringatan,
            'peringatan_ujung' => $peringatanUjung,
        ]);
    }

    private function barisHidup(Voucher $v, ?array $baca, array $login, bool $bernomor): array
    {
        $keterangan = [];
        $online     = false;
        $uptime     = (int) $v->uptime_used;
        $habis      = $v->expired_at;

        if ($baca === null) {
            [$label, $tone, $kategori] = self::STATUS[$v->status_efektif] ?? [$v->status_label, $v->status_tone, 'bermasalah'];
            $diRouter = '-';
        } else {
            $calon = $baca['users'][$v->username] ?? [];
            $pilih = $this->vouchers->pilihBaris($calon, (string) $v->password, $baca['ada_password']);

            if ($pilih === null) {
                [$label, $tone, $kategori] = [$calon ? 'Password di router beda' : 'Tidak ada di router', 'err', 'bermasalah'];
                $diRouter = $calon ? 'Ada, password beda' : 'Tidak ada';

                if (! $calon && $v->sync_status !== 'success') {
                    $keterangan[] = "Sinkron: {$v->sync_status}";
                }
            } elseif ($pilih === false) {
                [$label, $tone, $kategori] = ['Tidak bisa dipastikan', 'warn', 'bermasalah'];
                $diRouter     = 'Ada';
                $keterangan[] = 'Ada beberapa user bernama sama dan router tidak memberi password.';
            } else {
                $keadaan = $this->vouchers->keadaanDariRouter($v, $pilih, $baca['zona']);
                [$label, $tone, $kategori] = self::STATUS[$keadaan['status']];
                $diRouter = 'Ada';
                $online   = isset($baca['online'][$v->username]);
                $uptime   = (int) $keadaan['uptime_used'];
                $habis    = $keadaan['expired_at'];
            }
        }

        return [
            'urut'     => $bernomor ? (int) $v->nomor : (int) $v->id,
            'kategori' => $kategori,
            'masih'    => false,
            'baris'    => [
                'nomor'         => $v->nomor,
                'username'      => $v->username,
                'password'      => (string) $v->password,
                'label'         => $label,
                'tone'          => $tone,
                'di_router'     => $diRouter,
                'online'        => $online,
                'login_pertama' => $login[$v->username] ?? $v->activated_at?->format('d M Y H:i'),
                'habis_pada'    => $habis?->format('d M Y H:i'),
                'terpakai'      => $this->lookup->durasi($uptime),
                'keterangan'    => $keterangan,
                'sumber'        => 'hidup',
            ],
        ];
    }

    private function barisHapus(DeletedVoucher $d, ?array $baca, array $login, bool $bernomor): array
    {
        $penghapus  = $d->penghapus?->name ?? $d->penghapus?->username ?? '-';
        $keterangan = [
            'Dihapus ' . $d->deleted_at->format('d M Y H:i') . " ({$d->cara_label}) oleh {$penghapus}",
            "Status saat dihapus: {$d->status_label}",
            $d->hasil_router_label,
        ];

        $masih = false;
        if ($baca !== null) {
            foreach ($baca['users'][$d->username] ?? [] as $r) {
                if (array_key_exists('password', $r) && (string) $r['password'] === (string) $d->password) {
                    $masih = true;
                    break;
                }
            }

            if ($masih) {
                $keterangan[] = 'Masih ada di router';
            }
        }

        return [
            'urut'     => $bernomor ? (int) $d->nomor : (int) $d->voucher_id,
            'kategori' => 'dihapus',
            'masih'    => $masih,
            'baris'    => [
                'nomor'         => $d->nomor,
                'username'      => $d->username,
                'password'      => (string) $d->password,
                'label'         => 'Dihapus',
                'tone'          => '',
                'di_router'     => $baca === null ? '-' : ($masih ? 'Masih ada' : 'Tidak ada'),
                'online'        => false,
                'login_pertama' => $login[$d->username] ?? $d->activated_at?->format('d M Y H:i'),
                'habis_pada'    => $d->expired_at?->format('d M Y H:i'),
                'terpakai'      => $this->lookup->durasi((int) $d->uptime_used),
                'keterangan'    => $keterangan,
                'sumber'        => 'terhapus',
            ],
        ];
    }

    private function loginPertama(?int $routerId, string $kodeBatch, array $nama): array
    {
        if (! $routerId || ! $nama) {
            return [];
        }

        $asal  = Str::before($kodeBatch, '@');
        $hasil = [];

        $penjualan = VoucherSale::where('router_id', $routerId)
            ->whereIn('username', array_values(array_unique($nama)))
            ->whereNotNull('sold_at')
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get(['username', 'sold_at', 'waktu_ragu', 'batch_comment']);

        foreach ($penjualan->groupBy(fn ($s) => (string) $s->username) as $user => $daftar) {
            $pilih = $daftar->first(fn ($s) => (string) $s->batch_comment === $asal) ?? $daftar->first();

            $hasil[(string) $user] = $pilih->sold_at->format('d M Y H:i') . ($pilih->waktu_ragu ? ' (waktu ragu)' : '');
        }

        return $hasil;
    }

    private function kepala(?VoucherBatch $batch, int $batchId, Collection $hapus): array
    {
        $jumlah = Voucher::where('batch_id', $batchId)->count() + DeletedVoucher::where('batch_id', $batchId)->count();

        if ($batch) {
            return [
                'kode'     => $batch->code,
                'label'    => $batch->label,
                'router'   => $batch->router_label,
                'profil'   => $batch->profile,
                'harga'    => $batch->price,
                'validity' => $batch->validity ?: '-',
                'dibuat'   => $batch->created_at?->format('d M Y H:i') ?? '-',
                'pembuat'  => $batch->creator?->name ?? $batch->creator?->username ?? '-',
                'jumlah'   => $jumlah,
                'tautan'   => route('vouchers.show', $batch),
            ];
        }

        $contoh = $hapus->first() ?? DeletedVoucher::where('batch_id', $batchId)->first();

        return [
            'kode'     => $contoh?->batch_code,
            'label'    => null,
            'router'   => $contoh?->router_name ?? '-',
            'profil'   => $contoh?->profile,
            'harga'    => $contoh?->price,
            'validity' => '-',
            'dibuat'   => '-',
            'pembuat'  => '-',
            'jumlah'   => $jumlah,
            'tautan'   => null,
        ];
    }

    private function galat(string $pesan): array
    {
        return array_replace(self::KOSONG, ['status' => 'galat', 'pesan' => $pesan]);
    }
}
