<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\RencanaImportMikhmon;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoucherImportService
{
    private const POTONG = 500;

    public function __construct(
        private RouterGateway $gateway,
        private VoucherRouterService $routers,
        private VoucherService $vouchers,
    ) {}

    public function rencana(Router $router): array
    {
        $klien = $this->gateway->klien($router->slug, $router->toConfig());

        $users   = $klien->query('/ip/hotspot/user/print', [
            '=.proplist' => 'name,password,profile,comment,server,limit-uptime,limit-bytes-total',
        ]);
        $records = array_column($klien->query('/system/script/print', ['=.proplist' => 'name', '?comment' => 'mikhmon']), 'name');
        $profil  = collect($this->routers->profiles($router))->keyBy('name')->all();

        $sudahAda = Voucher::where('router_id', $router->id)->pluck('username')
            ->mapWithKeys(fn ($u) => [mb_strtolower($u) => true])
            ->all();

        return RencanaImportMikhmon::susun(
            $users,
            $records,
            $profil,
            $sudahAda,
            $router->slug,
            $this->routers->clockZone($router),
            new DateTimeZone(config('app.timezone')),
        );
    }

    public static function pratinjau(array $rencana): array
    {
        $batch = $rencana['batch'];
        usort($batch, fn ($a, $b) => $b['jumlah'] <=> $a['jumlah']);

        return ['ringkasan' => $rencana['ringkasan'], 'batch' => $batch];
    }

    public function impor(Router $router, ?int $userId = null): array
    {
        $lock = Cache::lock("voucher.import.{$router->slug}", 900);

        if (! $lock->get()) {
            throw new RuntimeException("Import voucher untuk {$router->name} sedang berjalan.");
        }

        try {
            $rencana = $this->rencana($router);
            $idBatch = $this->simpanBatch($router, $rencana['batch'], $userId);
            $masuk   = $this->simpanVoucher($router, $rencana['voucher'], $idBatch);

            foreach ($idBatch as $id) {
                $n = Voucher::where('batch_id', $id)->count();
                VoucherBatch::whereKey($id)->update(['quantity' => $n, 'synced_count' => $n, 'status' => 'success', 'synced_at' => now()]);
            }

            $keadaan = $this->vouchers->reconcile($router);

            $tertaut = DB::table('voucher_sales as s')
                ->join('vouchers as v', function ($j) {
                    $j->on('v.router_id', '=', 's.router_id')->on('v.username', '=', 's.username');
                })
                ->where('s.router_id', $router->id)
                ->whereNull('s.voucher_id')
                ->update(['s.voucher_id' => DB::raw('v.id')]);

            return [
                'ringkasan'         => $rencana['ringkasan'],
                'batch'             => count($idBatch),
                'masuk'             => $masuk,
                'keadaan'           => $keadaan,
                'penjualan_tertaut' => $tertaut,
            ];
        } finally {
            $lock->release();
        }
    }

    private function simpanBatch(Router $router, array $batch, ?int $userId): array
    {
        $id = [];

        foreach ($batch as $b) {
            $id[$b['kode']] = VoucherBatch::firstOrCreate(['code' => $b['kode']], [
                'router_id'   => $router->id,
                'router_name' => $router->name,
                'label'       => mb_substr($b['comment'], 0, 60),
                'profile'     => mb_substr($b['profile'], 0, 64),
                'quantity'    => 0,
                'mode'        => $b['mode'],
                'charset'     => 'lower',
                'code_length' => $b['code_length'],
                'validity'    => $b['validity'],
                'price'       => $b['price'],
                'status'      => 'success',
                'source'      => 'mikhmon_import',
                'created_by'  => $userId,
            ])->id;
        }

        return $id;
    }

    private function simpanVoucher(Router $router, array $voucher, array $idBatch): int
    {
        $sekarang = now();
        $masuk    = 0;

        foreach (array_chunk($voucher, self::POTONG) as $potong) {
            $masuk += DB::table('vouchers')->insertOrIgnore(array_map(fn ($v) => [
                'batch_id'     => $idBatch[$v['batch']],
                'router_id'    => $router->id,
                'username'     => $v['username'],
                'password'     => $v['password'],
                'profile'      => $v['profile'],
                'server'       => $v['server'],
                'comment'      => $v['comment'],
                'limit_uptime' => $v['limit_uptime'],
                'limit_bytes'  => $v['limit_bytes'],
                'price'        => $v['price'],
                'status'       => 'ready',
                'sync_status'  => 'success',
                'synced_at'    => $sekarang,
                'activated_at' => $v['activated_at'],
                'created_at'   => $sekarang,
                'updated_at'   => $sekarang,
            ], $potong));
        }

        return $masuk;
    }
}
