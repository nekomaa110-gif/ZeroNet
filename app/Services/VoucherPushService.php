<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Support\Collection;

class VoucherPushService
{
    public const KIRIM = 'kirim';

    private ?array $diRouter = null;

    public function __construct(
        private VoucherRouterService $routers,
        private VoucherService $vouchers,
        private RouterGateway $gateway,
    ) {}

    public function push(VoucherBatch $batch, ?callable $progress = null): array
    {
        $router = $batch->router;

        if (! $router) {
            $batch->update(['status' => 'failed', 'error' => 'Router batch ini sudah dihapus dari panel.']);

            return ['ok' => 0, 'failed' => 0];
        }

        return $this->gateway->kunciTulis($router->slug, fn () => $this->pushTerkunci($batch, $router, $progress));
    }

    private function pushTerkunci(VoucherBatch $batch, Router $router, ?callable $progress): array
    {
        $this->diRouter = null;

        $pending = $batch->vouchers()->where('sync_status', '!=', 'success')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            $this->vouchers->rangkumBatch($batch);

            return ['ok' => 0, 'failed' => 0];
        }

        $batch->update(['status' => 'syncing', 'error' => null]);

        $hitung = ['ok' => 0, 'failed' => 0];

        if ($pending->contains(fn (Voucher $v) => $v->sync_status !== 'pending')) {
            $this->diRouter = $this->routers->userIdentities($router);
            $pending        = $pending->reject(function (Voucher $v) use (&$hitung) {
                return $this->selesaiDiRouter($v, $hitung);
            })->values();
        }

        $total = $pending->count();
        $done  = 0;

        foreach ($pending->chunk((int) config('voucher.sync_chunk', 25)) as $potong) {
            Voucher::whereKey($potong->modelKeys())->update(['sync_status' => self::KIRIM, 'sync_error' => null]);

            $hasil = $this->routers->addUsers($router, $this->baris($potong));

            foreach ($potong->values() as $i => $voucher) {
                $this->catatHasil($router, $voucher, $hasil[$i] ?? ['ok' => false, 'error' => 'tidak ada balasan router'], $hitung);
            }

            $done += $potong->count();
            $batch->forceFill(['synced_count' => $batch->vouchers()->where('sync_status', 'success')->count()])->saveQuietly();
            $progress && $progress($done, $total);
        }

        $this->vouchers->rangkumBatch($batch);

        return $hitung;
    }

    private function selesaiDiRouter(Voucher $voucher, array &$hitung): bool
    {
        $diRouter = $this->diRouter[mb_strtolower($voucher->username)] ?? null;

        if (! $diRouter) {
            return false;
        }

        $this->tandai($voucher, $this->milikVoucherIni($voucher, $diRouter), self::pesanBentrok(), $hitung);

        return true;
    }

    private function catatHasil(Router $router, Voucher $voucher, array $r, array &$hitung): void
    {
        $error = (string) ($r['error'] ?? '');

        if ($r['ok'] || ! str_contains(mb_strtolower($error), 'already have')) {
            $this->tandai($voucher, (bool) $r['ok'], $error, $hitung);

            return;
        }

        try {
            $this->diRouter ??= $this->routers->userIdentities($router);
        } catch (\Throwable) {
            $this->tandai($voucher, false, VoucherService::BENTROK_PREFIX . ' belum dicek: nama sudah ada di router tapi pemiliknya gagal dibaca. Sinkron ulang nanti.', $hitung);

            return;
        }

        $this->tandai(
            $voucher,
            $this->milikVoucherIni($voucher, $this->diRouter[mb_strtolower($voucher->username)] ?? null),
            self::pesanBentrok(),
            $hitung,
        );
    }

    private function tandai(Voucher $voucher, bool $sukses, string $error, array &$hitung): void
    {
        $voucher->update([
            'sync_status' => $sukses ? 'success' : 'failed',
            'sync_error'  => $sukses ? null : mb_substr($error, 0, 255),
            'synced_at'   => $sukses ? now() : null,
        ]);

        $sukses ? $hitung['ok']++ : $hitung['failed']++;
    }

    private function milikVoucherIni(Voucher $voucher, ?array $diRouter): bool
    {
        if (! $diRouter) {
            return false;
        }

        return $diRouter['password'] !== null
            ? $diRouter['password'] === (string) $voucher->password
            : $diRouter['comment'] === (string) $voucher->comment;
    }

    private function baris(Collection $potong): array
    {
        return $potong->map(fn (Voucher $v) => [
            'name'         => $v->username,
            'password'     => $v->password,
            'profile'      => $v->profile,
            'server'       => $v->server,
            'limit_uptime' => $v->limit_uptime,
            'limit_bytes'  => $v->limit_bytes,
            'comment'      => $v->comment,
        ])->values()->all();
    }

    private static function pesanBentrok(): string
    {
        return VoucherService::BENTROK_PREFIX . ' dengan user lain di router (nama sama, comment/password berbeda).';
    }
}
