<?php

namespace App\Services;

use App\Jobs\SyncVoucherBatch;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoucherService
{
    public const BENTROK_PREFIX = 'Kode bentrok';

    public function __construct(
        private VoucherRouterService $routers,
        private VoucherCodeGenerator $codes,
        private VoucherLookupService $lookup,
    ) {}

    public function generate(Router $router, array $in, ?int $userId): VoucherBatch
    {
        $quantity = (int) $in['quantity'];
        $profile  = (string) $in['profile'];

        $meta = collect($this->routers->profiles($router))->firstWhere('name', $profile);

        if (! $meta) {
            throw new RuntimeException("Profil \"{$profile}\" tidak ada di router {$router->name}.");
        }

        $terpakai = $this->kodeTerpakai($router);

        $in += ['label' => '', 'server' => '', 'prefix' => '', 'suffix' => '', 'limit_uptime' => '', 'limit_bytes' => null];

        $pasangan = $this->codes->make(
            quantity: $quantity,
            charset: (string) $in['charset'],
            length: (int) $in['code_length'],
            mode: (string) $in['mode'],
            passwordLength: (int) ($in['password_length'] ?? min(8, max(3, (int) $in['code_length']))),
            prefix: (string) $in['prefix'],
            suffix: (string) $in['suffix'],
            taken: $terpakai,
        );

        $batch = DB::transaction(function () use ($router, $in, $meta, $pasangan, $quantity, $profile, $userId) {
            $batch = VoucherBatch::create([
                'router_id'       => $router->id,
                'router_name'     => $router->name,
                'code'            => $this->kodeBatchUnik((string) $in['mode'], (string) ($in['label'] ?? '')),
                'label'           => $in['label'] ?: null,
                'profile'         => $profile,
                'server'          => $in['server'] ?: null,
                'quantity'        => $quantity,
                'mode'            => (string) $in['mode'],
                'charset'         => (string) $in['charset'],
                'code_length'     => (int) $in['code_length'],
                'password_length' => $in['mode'] === 'up' ? (int) ($in['password_length'] ?? min(8, max(3, (int) $in['code_length']))) : null,
                'prefix'          => $in['prefix'] ?: null,
                'suffix'          => $in['suffix'] ?: null,
                'limit_uptime'    => $in['limit_uptime'] ?: null,
                'limit_bytes'     => $in['limit_bytes'] ?: null,
                'validity'        => $meta['validity'] ?: null,
                'price'           => $meta['price'] ?: null,
                'status'          => 'pending',
                'created_by'      => $userId,
            ]);

            $now  = now();
            $baris = [];

            foreach ($pasangan as $p) {
                $baris[] = [
                    'batch_id'     => $batch->id,
                    'router_id'    => $router->id,
                    'username'     => $p['username'],
                    'password'     => $p['password'],
                    'profile'      => $profile,
                    'server'       => $batch->server,
                    'comment'      => $batch->code,
                    'limit_uptime' => $batch->limit_uptime,
                    'limit_bytes'  => $batch->limit_bytes,
                    'price'        => $batch->price,
                    'status'       => 'ready',
                    'sync_status'  => 'pending',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }

            foreach (array_chunk($baris, 200) as $potong) {
                Voucher::insert($potong);
            }

            return $batch;
        });

        SyncVoucherBatch::dispatch($batch->id);

        return $batch;
    }

    public function tambahSatu(Router $router, array $in, ?int $userId): VoucherBatch
    {
        $username = trim((string) $in['username']);
        $password = trim((string) ($in['password'] ?? '')) ?: $username;
        $profile  = (string) $in['profile'];
        $mode     = $password === $username ? 'vc' : 'up';
        $catatan  = trim((string) ($in['comment'] ?? ''));

        $meta = collect($this->routers->profiles($router))->firstWhere('name', $profile);
        if (! $meta) {
            throw new RuntimeException("Profil \"{$profile}\" tidak ada di router {$router->name}.");
        }

        if (Voucher::where('router_id', $router->id)->where('username', $username)->exists()
            || $this->routers->client($router)->query('/ip/hotspot/user/print', ['?name' => $username, '=.proplist' => 'name'])) {
            throw new RuntimeException("Nama \"{$username}\" sudah dipakai di {$router->name}. Nama voucher harus unik.");
        }

        $comment = "{$mode}-{$catatan}";
        $dasar   = $catatan !== '' ? $comment : "{$mode}-{$username}";
        $code    = $dasar;
        for ($n = 2; VoucherBatch::where('code', $code)->exists(); $n++) {
            $code = mb_substr($dasar, 0, 58) . "-{$n}";
        }

        return DB::transaction(function () use ($router, $in, $meta, $username, $password, $profile, $mode, $catatan, $comment, $code, $userId) {
            $batch = VoucherBatch::create([
                'router_id'       => $router->id,
                'router_name'     => $router->name,
                'code'            => $code,
                'label'           => $catatan ?: null,
                'profile'         => $profile,
                'server'          => ($in['server'] ?? '') ?: null,
                'quantity'        => 1,
                'mode'            => $mode,
                'charset'         => 'manual',
                'code_length'     => min(255, mb_strlen($username)),
                'password_length' => $mode === 'up' ? min(255, mb_strlen($password)) : null,
                'limit_uptime'    => ($in['limit_uptime'] ?? '') ?: null,
                'limit_bytes'     => ($in['limit_bytes'] ?? null) ?: null,
                'validity'        => $meta['validity'] ?: null,
                'price'           => $meta['price'] ?: null,
                'status'          => 'pending',
                'created_by'      => $userId,
            ]);

            Voucher::create([
                'batch_id'     => $batch->id,
                'router_id'    => $router->id,
                'username'     => $username,
                'password'     => $password,
                'profile'      => $profile,
                'server'       => $batch->server,
                'comment'      => $comment,
                'limit_uptime' => $batch->limit_uptime,
                'limit_bytes'  => $batch->limit_bytes,
                'price'        => $batch->price,
                'status'       => 'ready',
                'sync_status'  => 'pending',
            ]);

            return $batch;
        });
    }

    private function kodeTerpakai(Router $router): array
    {
        $terpakai = $this->routers->existingUsernames($router);

        Voucher::query()
            ->select('username')
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$terpakai) {
                foreach ($rows as $row) {
                    $terpakai[mb_strtolower($row->username)] = true;
                }
            });

        return $terpakai;
    }

    private function kodeBatchUnik(string $mode, string $label): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = $this->codes->batchCode($mode, $label);

            if (! VoucherBatch::where('code', $code)->exists()) {
                return $code;
            }
        }

        return $this->codes->batchCode($mode, $label) . '-' . uniqid();
    }

    public function rangkumBatch(VoucherBatch $batch): void
    {
        $ok    = $batch->vouchers()->where('sync_status', 'success')->count();
        $gagal = $batch->vouchers()->where('sync_status', 'failed')->count();

        $batch->update([
            'synced_count' => $ok,
            'failed_count' => $gagal,
            'synced_at'    => $ok > 0 ? now() : $batch->synced_at,
            'status'       => match (true) {
                $gagal === 0 && $ok >= $batch->quantity => 'success',
                $ok === 0 && $gagal > 0                 => 'failed',
                $gagal > 0                              => 'partial',
                default                                 => 'syncing',
            },
        ]);
    }

    public function reconcile(Router $router, bool $dry = false): array
    {
        $state  = $this->routers->userStates($router);
        $zona   = $this->routers->clockZone($router);
        $angka  = ['diperiksa' => 0, 'aktif' => 0, 'habis' => 0, 'hilang' => 0];

        Voucher::query()
            ->where('router_id', $router->id)
            ->where('sync_status', 'success')
            ->with('batch:id,validity')
            ->chunkById(500, function ($vouchers) use ($state, $zona, &$angka, $dry) {
                foreach ($vouchers as $voucher) {
                    $angka['diperiksa']++;
                    $baris = $state[mb_strtolower($voucher->username)] ?? null;

                    if (! $baris) {
                        $angka['hilang']++;
                        if (! $dry && $voucher->status !== 'missing') {
                            $voucher->update(['status' => 'missing', 'checked_at' => now()]);
                        }
                        continue;
                    }

                    $ubah = $this->bacaKeadaan($voucher, $baris, $zona);

                    if ($ubah['status'] === 'active')                       $angka['aktif']++;
                    if (in_array($ubah['status'], ['expired', 'disabled'])) $angka['habis']++;

                    if (! $dry) {
                        $voucher->update($ubah);
                    }
                }
            });

        return $angka;
    }

    private function bacaKeadaan(Voucher $voucher, array $baris, \DateTimeZone $zona): array
    {
        $uptime   = $this->lookup->keDetik($baris['uptime'] ?? '');
        $jatah    = $this->lookup->keDetik($baris['limit-uptime'] ?? '');
        $mati1s   = ($baris['limit-uptime'] ?? '') === '1s';
        $nonaktif = ($baris['disabled'] ?? 'false') === 'true';
        $comment  = trim($baris['comment'] ?? '');

        $exp = $this->lookup->keWaktu($comment);
        $expiredAt = $exp
            ? Carbon::createFromFormat('Y-m-d H:i:s', $exp->format('Y-m-d H:i:s'), $zona)->setTimezone(config('app.timezone'))
            : null;

        $activatedAt = $voucher->activated_at;
        if (! $activatedAt && $expiredAt && $voucher->batch?->validity) {
            $detik = $this->lookup->keDetik((string) $voucher->batch->validity);
            $activatedAt = $detik > 0 ? $expiredAt->copy()->subSeconds($detik) : null;
        }
        if (! $activatedAt && $uptime > 0) {
            $activatedAt = now();
        }

        $batasHabis = now()->subSeconds(Voucher::jedaPengawas());

        $status = match (true) {
            $nonaktif                                   => 'disabled',
            $mati1s                                     => 'expired',
            $jatah > 0 && $uptime >= $jatah             => 'expired',
            $expiredAt && $expiredAt->lte($batasHabis)  => 'expired',
            $uptime > 0 || $expiredAt !== null          => 'active',
            default                                     => 'ready',
        };

        return [
            'status'       => $status,
            'uptime_used'  => $uptime,
            'expired_at'   => $expiredAt,
            'activated_at' => $activatedAt,
            'profile'      => $baris['profile'] ?? $voucher->profile,
            'checked_at'   => now(),
        ];
    }

    public function stats(?int $routerId = null): array
    {
        $q = Voucher::query()->when($routerId, fn ($x) => $x->where('router_id', $routerId));

        $sql = Voucher::statusEfektifSql();

        $baris = (clone $q)
            ->selectRaw("{$sql} as s, sync_status = 'success' as masuk, count(*) as n", Voucher::ikatanStatusEfektif())
            ->groupBy('s', 'masuk')
            ->get();

        $hitung = fn (string $s) => (int) $baris->where('s', $s)->sum('n');

        return [
            'total'    => (int) $baris->sum('n'),
            'ready'    => (int) $baris->where('s', 'ready')->where('masuk', 1)->sum('n'),
            'active'   => $hitung('active'),
            'expired'  => $hitung('expired'),
            'disabled' => $hitung('disabled'),
            'missing'  => $hitung('missing'),
            'terjual'  => $hitung('active') + $hitung('expired'),
            'omzet'    => (int) VoucherSale::query()->where('waktu_ragu', false)->when($routerId, fn ($x) => $x->where('router_id', $routerId))->sum('price'),
            'gagal'    => (int) (clone $q)->where('sync_status', 'failed')->count(),
        ];
    }
}
