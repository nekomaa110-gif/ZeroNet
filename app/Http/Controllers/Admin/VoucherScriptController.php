<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\PackageService;
use App\Services\RouterScriptSnapshot;
use App\Services\VoucherRouterService;
use App\Services\VoucherScriptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class VoucherScriptController extends Controller
{
    public function __construct(
        private VoucherRouterService $routers,
        private VoucherScriptBuilder $builder,
    ) {}

    public function index()
    {
        return view('vouchers.scripts', [
            'routers' => Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name', 'host']),
            'modes'   => config('voucher.expired_modes'),
        ]);
    }

    public function status(Router $router): JsonResponse
    {
        try {
            $status = $this->routers->scriptStatus($router);

            $usage  = $this->routers->profileUsage($router);
            $pools  = $this->routers->pools($router);
            $queues = $this->routers->parentQueues($router);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        return response()->json([
            'success'  => true,
            'router'   => ['slug' => $router->slug, 'name' => $router->name],
            'version'  => VoucherScriptBuilder::plainVersion($status['version']),
            'iso_date' => $status['iso_date'],
            'script'   => [
                'installed' => $status['script_installed'],
                'current'   => $status['script_current'],
            ],
            'scheduler'         => $status['scheduler'],
            'legacy_schedulers' => $status['legacy_schedulers'],
            'pools'             => $pools,
            'queues'            => $queues,
            'profiles'          => collect($status['profiles'])->map(fn ($p) => [
                'name'      => $p['name'],
                'rate'      => $p['rate_limit'],
                'shared'    => $p['shared_users'],
                'pool'      => $p['address_pool'],
                'parent'    => $p['parent_queue'],
                'idle'      => $p['idle_timeout'],
                'expmode'   => $p['expmode'],
                'mode'      => str_starts_with($p['expmode'], 'rem') ? 'rem' : ($p['expmode'] !== '' ? 'ntf' : 'off'),
                'record'    => $p['record'],
                'price'     => $p['price'],
                'sprice'    => $p['sprice'],
                'validity'  => $p['validity'],
                'lock'      => $p['lock'],
                'managed'   => $p['managed'],
                'has_script'=> $p['has_script'],
                'usage'     => $usage[$p['name']] ?? 0,

                'locked'    => \App\Services\VoucherRouterService::profilTerlindungi($p['name']),

                'safe_name' => \App\Services\VoucherRouterService::namaProfilAman($p['name']),
            ])->values(),
        ]);
    }

    public function install(Request $request, Router $router): JsonResponse
    {
        $data = $request->validate([
            'profiles'             => ['required', 'array', 'min:1'],
            'profiles.*.name'      => ['required', 'string', 'max:64'],
            'profiles.*.mode'      => ['required', Rule::in(['off', 'ntf', 'rem'])],
            'profiles.*.record'    => ['nullable', 'boolean'],
            'profiles.*.lock'      => ['nullable', 'boolean'],
            'profiles.*.validity'  => ['nullable', 'string', 'max:20', 'regex:/^(\d+[wdhms])*$/'],
            'profiles.*.price'     => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'profiles.*.sprice'    => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'drop_legacy'          => ['nullable', 'boolean'],
            'snapshot'             => ['nullable', 'boolean'],
        ], [
            'profiles.required'         => 'Tidak ada profil yang dikirim.',
            'profiles.*.validity.regex' => 'Format masa aktif salah. Contoh: 1d, 5h, 7d.',
        ]);

        $settings = [];

        foreach ($data['profiles'] as $p) {
            $mode = $p['mode'];

            if ($mode !== 'off' && empty($p['validity'])) {
                return response()->json([
                    'success' => false,
                    'error'   => "Profil {$p['name']}: masa aktif wajib diisi kalau kedaluwarsanya diawasi.",
                ], 422);
            }

            $settings[$p['name']] = [

                'expmode'  => $mode === 'off' ? 'off' : $mode . (! empty($p['record']) ? 'c' : ''),
                'record'   => (bool) ($p['record'] ?? false),
                'lock'     => (bool) ($p['lock'] ?? false),
                'validity' => (string) ($p['validity'] ?? ''),
                'price'    => (int) ($p['price'] ?? 0),
                'sprice'   => (int) ($p['sprice'] ?? 0),
            ];
        }

        try {
            $snapshot = ! empty($data['snapshot']) ? basename(app(RouterScriptSnapshot::class)->simpan($router)['berkas']) : null;
            $laporan  = $this->routers->installScripts($router, $settings, (bool) ($data['drop_legacy'] ?? false));
            $laporan['snapshot'] = $snapshot;
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
            PackageService::lupakanProfilRouter($router->slug);
        }

        ActivityLogService::log(
            action: 'voucher_script_install',
            description: "Memasang script voucher di router {$router->name}: script {$laporan['script']}, "
                . "scheduler {$laporan['scheduler']}, {$laporan['legacy_removed']} scheduler lama dicabut",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: $laporan,
        );

        $pesan = "Script {$laporan['script']}, scheduler {$laporan['scheduler']}.";
        if ($laporan['legacy_removed'] > 0) {
            $pesan .= " {$laporan['legacy_removed']} scheduler lama dicabut.";
        }
        if ($laporan['snapshot']) {
            $pesan .= ' Keadaan sebelumnya tersimpan untuk pemulihan.';
        }
        if (isset($laporan['script_policy_warning'])) {
            $pesan .= ' ' . $laporan['script_policy_warning'];
        }

        return response()->json(['success' => true, 'message' => $pesan, 'laporan' => $laporan]);
    }

    public function preview(Router $router): JsonResponse
    {
        try {
            $status = $this->routers->scriptStatus($router);

            $modes = [];
            foreach ($status['profiles'] as $p) {
                if ($p['expmode'] !== '' && $p['validity'] !== '') {
                    $modes[$p['name']] = str_starts_with($p['expmode'], 'rem') ? 'rem' : 'ntf';
                }
            }

            return response()->json([
                'success'   => true,
                'login'     => $this->builder->loginScript($status['version']),
                'scheduler' => $this->builder->expireScript($modes),
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }
    }
}
