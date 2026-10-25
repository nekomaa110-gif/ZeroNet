<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Voucher;
use App\Support\MikhmonRecord;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Collection as KoleksiModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PencabutKartuService
{
    public const TTL_KUNCI = 600;

    public const TUNGGU_KUNCI = 5;

    public const PESAN_PUTUS = 'Koneksi ke router putus saat mencabut. Sebagian user mungkin sudah tercabut. Jalankan hapus lagi.';

    public const BATAS_TANGGAL_WAJAR = '2025-01-01 00:00:00';

    public const SELISIH_JAM_MAKS = 600;

    public const HASIL = ['dicabut', 'tidak_ada', 'ambigu', 'beda_password', 'beda_batch', 'belum_habis', 'tidak_di_router', 'tidak_terbukti', 'gagal'];

    public const DITAHAN = ['ambigu', 'beda_password', 'beda_batch', 'belum_habis', 'tidak_di_router', 'tidak_terbukti', 'gagal'];

    public const ALASAN = [
        'ambigu'          => 'Ada lebih dari satu user cocok di router',
        'beda_password'   => 'Password di router beda dengan panel',
        'beda_batch'      => 'User router milik batch lain',
        'belum_habis'     => 'Di router belum habis',
        'tidak_di_router' => 'Tidak ada di router, padahal di panel belum habis',
        'tidak_terbukti'  => 'Tercatat hilang dari router, user bernama sama tidak terbukti miliknya',
        'gagal'           => 'Gagal dicabut dari router',
    ];

    public function __construct(
        private VoucherRouterService $routers,
        private VoucherService $vouchers,
        private VoucherLookupService $lookup,
        private RouterGateway $gateway,
    ) {}

    public function nilai(Router $router, Collection $kartu, bool $wajibHabis): array
    {
        $this->mutakhirkanBatch($kartu);

        return $this->nilaiDariBacaan($kartu, $this->baca($router, $kartu, $wajibHabis), $wajibHabis);
    }

    public function cabut(Router $router, Collection $kartu, bool $wajibHabis): array
    {
        return $this->gateway->kunciTulis(
            $router->slug,
            fn () => $this->cabutTerkunci($router, $kartu, $wajibHabis),
            self::TUNGGU_KUNCI,
            self::TTL_KUNCI,
        );
    }

    private function cabutTerkunci(Router $router, Collection $kartu, bool $wajibHabis): array
    {
        $this->mutakhirkanBatch($kartu);

        $hasil = $this->nilaiDariBacaan($kartu, $this->baca($router, $kartu, $wajibHabis), $wajibHabis);
        $calon = array_filter($hasil['per_kartu'], fn ($h) => $h['hasil'] === 'dicabut');

        if ($calon === []) {
            return $hasil;
        }

        $this->catatMulai($router, $kartu, $calon);

        try {
            $masih = $this->cabutDanBacaUlang($router, $calon);
        } catch (Exception $e) {
            $this->routers->disconnect($router);

            throw new RuntimeException(self::PESAN_PUTUS, 0, $e);
        }

        foreach ($calon as $id => $h) {
            if (isset($masih[$h['router_id']])) {
                $hasil['per_kartu'][$id]['hasil'] = 'gagal';
            }
        }

        $hasil['ringkasan'] = array_fill_keys(self::HASIL, 0);
        foreach ($hasil['per_kartu'] as $h) {
            $hasil['ringkasan'][$h['hasil']]++;
        }

        return $hasil;
    }

    private function catatMulai(Router $router, Collection $kartu, array $calon): void
    {
        $nama   = $kartu->pluck('username', 'id');
        $daftar = [];
        foreach ($calon as $id => $h) {
            $daftar[] = [$id, (string) $nama[$id], $h['router_id']];
        }

        ActivityLogService::log(
            action: 'voucher_cabut_mulai',
            description: 'Mulai mencabut ' . count($daftar) . " user hotspot dari router {$router->name}",
            subjectType: 'router',
            subjectId: (string) $router->id,
            properties: ['router' => $router->slug, 'kartu' => $daftar],
        );

        foreach ($calon as $id => $h) {
            Cache::put('pencabut.dicoba.' . $id, true, 86400);
        }
    }

    private function cabutDanBacaUlang(Router $router, array $calon): array
    {
        $client   = $this->routers->client($router);
        $perintah = [];
        foreach ($calon as $h) {
            $perintah[] = ['cmd' => '/ip/hotspot/user/remove', 'params' => ['=.id' => $h['router_id']]];
        }

        $client->queryMany($perintah, (int) config('voucher.sync_chunk', 25));

        $masih = [];
        foreach ($client->query('/ip/hotspot/user/print', ['=.proplist' => '.id']) as $r) {
            $masih[(string) ($r['.id'] ?? '')] = true;
        }

        return $masih;
    }

    private function mutakhirkanBatch(Collection $kartu): void
    {
        foreach ($kartu as $v) {
            if ($v->relationLoaded('batch') && $v->batch && ! array_key_exists('created_at', $v->batch->getAttributes())) {
                $v->unsetRelation('batch');
            }
        }

        (new KoleksiModel($kartu->all()))->loadMissing('batch:id,created_at,validity,source');
    }

    private function baca(Router $router, Collection $kartu, bool $wajibHabis): array
    {
        $client = $this->routers->client($router);
        $rows   = $client->query('/ip/hotspot/user/print', [
            '=.proplist' => '.id,name,password,comment,uptime,limit-uptime,disabled',
        ]);
        $clock  = $client->query('/system/clock/print')[0] ?? [];

        if ($rows !== [] && ! array_filter($rows, fn ($r) => array_key_exists('password', $r))) {
            throw new RuntimeException('Router tidak memberi password user hotspot, hapus dibatalkan.');
        }

        if ($rows === [] && $kartu->contains(fn (Voucher $v) => in_array($v->sync_status, ['success', 'kirim'], true))) {
            throw new RuntimeException('Router membalas daftar user kosong, hapus dibatalkan.');
        }

        $zona = VoucherRouterService::zonaDariJam($clock);

        if ($wajibHabis) {
            $this->periksaJam($clock, $zona);
        }

        $baris = [];
        foreach ($rows as $r) {
            $nama = (string) ($r['name'] ?? '');
            if ($nama === '') {
                continue;
            }
            $baris[$nama][] = [
                '.id'          => (string) ($r['.id'] ?? ''),
                'password'     => (string) ($r['password'] ?? ''),
                'comment'      => (string) ($r['comment'] ?? ''),
                'uptime'       => (string) ($r['uptime'] ?? ''),
                'limit-uptime' => (string) ($r['limit-uptime'] ?? ''),
                'disabled'     => (string) ($r['disabled'] ?? 'false'),
            ];
        }

        return ['baris' => $baris, 'zona' => $zona];
    }

    private function periksaJam(array $clock, DateTimeZone $zona): void
    {
        $jam = MikhmonRecord::waktu(trim((string) ($clock['date'] ?? '')) . ' ' . trim((string) ($clock['time'] ?? '')), $zona);

        if (! $jam) {
            throw new RuntimeException('Jam router tidak terbaca, hapus Habis dibatalkan.');
        }

        $selisih = abs(now()->getTimestamp() - $jam->getTimestamp());

        if ($selisih > self::SELISIH_JAM_MAKS) {
            $menit = (int) ceil($selisih / 60);

            throw new RuntimeException("Jam router selisih {$menit} menit dengan server, hapus Habis dibatalkan.");
        }
    }

    private function nilaiDariBacaan(Collection $kartu, array $bacaan, bool $wajibHabis): array
    {
        $perKartu  = [];
        $ringkasan = array_fill_keys(self::HASIL, 0);

        foreach ($kartu as $v) {
            $hasil = $this->klasifikasi($v, $bacaan['baris'][(string) $v->username] ?? [], $bacaan['zona'], $wajibHabis);

            $perKartu[$v->id] = $hasil;
            $ringkasan[$hasil['hasil']]++;
        }

        return ['per_kartu' => $perKartu, 'ringkasan' => $ringkasan];
    }

    private function klasifikasi(Voucher $v, array $calon, DateTimeZone $zona, bool $wajibHabis): array
    {
        if ($calon === []) {
            $terbukti = ! $wajibHabis
                || in_array($v->status_efektif, ['expired', 'missing'], true)
                || Cache::has('pencabut.dicoba.' . $v->id);

            return $this->hasil($terbukti ? 'tidak_ada' : 'tidak_di_router');
        }

        $comment = (string) $v->comment;
        $sandi   = (string) $v->password;
        $cocok   = array_values(array_filter(
            $calon,
            fn ($r) => $r['password'] === $sandi && ($r['comment'] === $comment || MikhmonRecord::waktu($r['comment'], $zona) !== null),
        ));

        if (count($cocok) > 1) {
            return $this->hasil('ambigu');
        }

        if ($cocok === []) {
            $sandiSama = array_filter($calon, fn ($r) => $r['password'] === $sandi) !== [];

            return $this->hasil($sandiSama ? 'beda_batch' : 'beda_password');
        }

        $baris = $cocok[0];

        if ($v->status === 'missing' && $baris['comment'] !== $comment) {
            return $this->hasil('tidak_terbukti');
        }

        if (! $wajibHabis) {
            return $this->hasil('dicabut', $baris['.id']);
        }

        $keadaan = $this->vouchers->keadaanDariRouter($v, $baris, $zona);

        if ($keadaan['status'] !== 'expired' || ! $this->habisWajar($v, $baris, $keadaan)) {
            return $this->hasil('belum_habis', null, $keadaan);
        }

        return $this->hasil('dicabut', $baris['.id'], $keadaan);
    }

    private function habisWajar(Voucher $v, array $baris, array $keadaan): bool
    {
        $uptime = $this->lookup->keDetik($baris['uptime']);
        $jatah  = $this->lookup->keDetik($baris['limit-uptime']);

        if ($baris['limit-uptime'] === '1s' || ($jatah > 0 && $uptime >= $jatah)) {
            return true;
        }

        $expiredAt = $keadaan['expired_at'] ?? null;

        return $expiredAt !== null && $expiredAt->gte($this->batasWajar($v));
    }

    private function batasWajar(Voucher $v): Carbon
    {
        $zona = config('app.timezone');

        if ($v->batch && $v->batch->source !== 'mikhmon_import' && $v->batch->created_at) {
            return $v->batch->created_at->copy();
        }

        return Carbon::parse(self::BATAS_TANGGAL_WAJAR, $zona);
    }

    private function hasil(string $hasil, ?string $routerId = null, ?array $keadaan = null): array
    {
        return ['hasil' => $hasil, 'router_id' => $routerId, 'keadaan' => $keadaan];
    }
}
