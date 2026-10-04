<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VoucherSale;
use App\Support\MikhmonRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VoucherSalesService
{
    public const SPRICE_JENDELA_MENIT = 60;

    public const BULAN_DISIMPAN_DI_ROUTER = 3;

    private const POTONG = 500;

    private const HAPUS_PER_PERINTAH = 100;

    public function __construct(
        private RouterGateway $gateway,
        private VoucherRouterService $routers,
    ) {}

    public function tarik(Router $router, bool $penuh = false, bool $dry = false): array
    {
        $klien  = $this->gateway->klien($router->slug, $router->toConfig());
        $jumlah = $klien->count('/system/script/print', ['?comment' => 'mikhmon']);

        if (! $penuh && Cache::get($this->kunciJumlah($router)) === $jumlah) {
            if (! $dry) {
                $router->forceFill(['last_sales_pull_at' => now()])->saveQuietly();
            }

            return ['di_router' => $jumlah, 'baru' => 0, 'dilewati' => true];
        }

        $rows  = $klien->query('/system/script/print', ['=.proplist' => '.id,name', '?comment' => 'mikhmon']);
        $hasil = $this->simpan($router, $rows, $dry);

        if (! $dry) {
            Cache::forever($this->kunciJumlah($router), $jumlah);
            $router->forceFill(['last_sales_pull_at' => now()])->saveQuietly();
        }

        return ['di_router' => $jumlah, 'dilewati' => false] + $hasil;
    }

    public function arsip(Router $router, bool $jalan = false): array
    {
        return $this->gateway->kunciTulis($router->slug, function () use ($router, $jalan) {
            $klien = $this->gateway->klien($router->slug, $router->toConfig());
            $rows  = $klien->query('/system/script/print', ['=.proplist' => '.id,name', '?comment' => 'mikhmon']);

            $this->simpan($router, $rows, false);

            $batas     = now()->startOfMonth()->subMonths(self::BULAN_DISIMPAN_DI_ROUTER);
            $tersalin  = $this->salinan($router, array_map(fn ($r) => sha1((string) ($r['name'] ?? '')), $rows));
            $hapus     = [];
            $ringkasan = ['di_router' => count($rows), 'batas' => $batas->format('Y-m-d'), 'dihapus' => 0, 'tanpa_salinan' => 0, 'waktu_ragu' => 0, 'per_bulan' => []];

            foreach ($rows as $r) {
                $hash  = sha1((string) ($r['name'] ?? ''));
                $salin = $tersalin[$hash] ?? null;

                if (! $salin) {
                    $ringkasan['tanpa_salinan']++;
                    continue;
                }
                if ($salin->waktu_ragu || ! $salin->sold_at) {
                    $ringkasan['waktu_ragu']++;
                    continue;
                }
                if ($salin->sold_at->gte($batas)) {
                    continue;
                }

                $bulan = $salin->sold_at->format('Y-m');
                $ringkasan['per_bulan'][$bulan] = ($ringkasan['per_bulan'][$bulan] ?? 0) + 1;
                $hapus[$hash] = $r['.id'];
            }

            ksort($ringkasan['per_bulan']);

            if ($jalan) {
                foreach (array_chunk($hapus, self::HAPUS_PER_PERINTAH, true) as $potong) {
                    $klien->query('/system/script/remove', ['=.id' => implode(',', $potong)]);
                    VoucherSale::where('router_id', $router->id)
                        ->whereIn('record_hash', array_keys($potong))
                        ->update(['dihapus_dari_router_at' => now()]);
                    $ringkasan['dihapus'] += count($potong);
                }
                Cache::forget($this->kunciJumlah($router));
            }

            $ringkasan['akan_dihapus'] = count($hapus);

            return $ringkasan;
        }, ttl: 600);
    }

    private function simpan(Router $router, array $rows, bool $dry): array
    {
        $record = [];
        foreach ($rows as $r) {
            $nama = (string) ($r['name'] ?? '');
            if ($nama !== '') {
                $record[sha1($nama)] = ['nama' => $nama, 'id' => $r['.id'] ?? null];
            }
        }

        $baru = array_diff_key($record, $this->salinan($router, array_keys($record)));

        if (! $baru) {
            return ['baru' => 0, 'tak_terurai' => 0];
        }

        $zona     = $this->routers->clockZone($router);
        $profil   = collect($this->routers->profiles($router))->keyBy('name')->all();
        $sekarang = now();
        $baris    = [];
        $gagal    = 0;

        $terurai = [];
        foreach ($baru as $hash => $x) {
            $u = MikhmonRecord::urai($x['nama'], $zona);

            if ($u) {
                $terurai[$hash] = $u + ['nama' => $x['nama'], 'id' => $x['id']];
            } else {
                $gagal++;
            }
        }

        $voucher = $this->voucherMilik($router, array_map(fn ($u) => $u['user'], $terurai));

        foreach ($terurai as $hash => $u) {
            $waktu = $u['waktu'] ? Carbon::instance($u['waktu'])->setTimezone(config('app.timezone')) : null;
            $ragu  = ! $waktu || $waktu->gt($sekarang->copy()->addDay()) || $waktu->year < 2020;
            $harga = MikhmonRecord::angka($u['harga']);
            $v     = $voucher[mb_strtolower($u['user'])] ?? null;

            $baris[] = [
                'router_id'      => $router->id,
                'router_name'    => $router->name,
                'voucher_id'     => $v['id'] ?? null,
                'record_hash'    => $hash,
                'record_name'    => mb_substr($u['nama'], 0, 512),
                'router_item_id' => $u['id'] !== null ? mb_substr((string) $u['id'], 0, 16) : null,
                'sold_at'        => $waktu?->format('Y-m-d H:i:s'),
                'waktu_ragu'     => $ragu,
                'username'       => mb_substr($u['user'], 0, 64),
                'profile'        => $u['profile'] !== '' ? mb_substr($u['profile'], 0, 64) : null,
                'price'          => $harga,
                'validity'       => $u['masa'] !== '' ? mb_substr($u['masa'], 0, 20) : null,
                'ip'             => $u['ip'] !== '' ? mb_substr($u['ip'], 0, 45) : null,
                'mac'            => $u['mac'] !== '' ? mb_substr($u['mac'], 0, 17) : null,
                'batch_comment'  => $u['batch'] !== '' ? mb_substr($u['batch'], 0, 64) : null,
                'source'         => ($v['source'] ?? null) === 'panel' ? 'panel' : 'mikhmon',
                'created_at'     => $sekarang,
                'updated_at'     => $sekarang,
            ] + $this->hargaJual($profil[$u['profile']] ?? null, $harga, $waktu, $ragu, $sekarang);
        }

        $masuk = 0;
        if (! $dry) {
            foreach (array_chunk($baris, self::POTONG) as $potong) {
                $masuk += DB::table('voucher_sales')->insertOrIgnore($potong);
            }
            $this->tandaiTerpakai($baris);
        }

        return ['baru' => $dry ? count($baris) : $masuk, 'tak_terurai' => $gagal];
    }

    private function tandaiTerpakai(array $baris): void
    {
        foreach ($baris as $b) {
            if (! $b['voucher_id'] || $b['waktu_ragu'] || ! $b['sold_at']) {
                continue;
            }

            DB::table('vouchers')
                ->where('id', $b['voucher_id'])
                ->where('status', 'ready')
                ->whereRaw('COALESCE(checked_at, created_at) < ?', [$b['sold_at']])
                ->update(['status' => 'active', 'activated_at' => $b['sold_at'], 'updated_at' => now()]);
        }
    }

    private function hargaJual(?array $profil, ?int $harga, ?Carbon $waktu, bool $ragu, Carbon $sekarang): array
    {
        $dekat = ! $ragu && $waktu && abs($sekarang->diffInMinutes($waktu)) <= self::SPRICE_JENDELA_MENIT;
        $cocok = $profil && ($profil['sprice'] ?? 0) > 0 && $harga !== null && $harga === (int) ($profil['price'] ?? -1);

        return $dekat && $cocok
            ? ['sprice' => (int) $profil['sprice'], 'sprice_sumber' => 'profil_saat_tarik']
            : ['sprice' => null, 'sprice_sumber' => null];
    }

    private function salinan(Router $router, array $hash): array
    {
        $ada = [];

        foreach (array_chunk(array_values(array_unique($hash)), 1000) as $potong) {
            VoucherSale::where('router_id', $router->id)
                ->whereIn('record_hash', $potong)
                ->get(['record_hash', 'sold_at', 'waktu_ragu'])
                ->each(function ($s) use (&$ada) {
                    $ada[$s->record_hash] = $s;
                });
        }

        return $ada;
    }

    private function voucherMilik(Router $router, array $nama): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique($nama)), 1000) as $potong) {
            DB::table('vouchers')
                ->join('voucher_batches', 'voucher_batches.id', '=', 'vouchers.batch_id')
                ->where('vouchers.router_id', $router->id)
                ->whereIn('vouchers.username', $potong)
                ->get(['vouchers.id', 'vouchers.username', 'voucher_batches.source'])
                ->each(function ($v) use (&$hasil) {
                    $hasil[mb_strtolower($v->username)] = ['id' => $v->id, 'source' => $v->source];
                });
        }

        return $hasil;
    }

    private function kunciJumlah(Router $router): string
    {
        return "voucher.penjualan.jumlah.{$router->id}";
    }
}
