<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\LoadBalanceConfigService;
use App\Services\LoadBalanceService;
use App\Services\MikrotikService;
use App\Services\TrafficService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class LoadBalanceController extends Controller
{
    public function __construct(
        private LoadBalanceService $lb,
        private MikrotikService $mikrotik,
        private LoadBalanceConfigService $config,
        private TrafficService $traffic,
    ) {}

    public function config(string $router)
    {
        $this->mikrotik->routerConfig($router);

        try {
            return response()->json(['ok' => true] + $this->config->current($router));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Router tidak dapat dihubungi: ' . $e->getMessage()], 502);
        }
    }

    public function preview(Request $request, string $router)
    {
        $this->mikrotik->routerConfig($router);
        $input = $this->changeInput($request);

        try {
            $plan = $this->config->plan($router, $input);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Router tidak dapat dihubungi: ' . $e->getMessage()], 502);
        }

        return response()->json([
            'ok'           => true,
            'commands'     => $plan['text'],
            'hash'         => $plan['hash'],
            'moves_tunnel' => $plan['moves_tunnel'],
        ]);
    }

    public function apply(Request $request, string $router)
    {
        $cfg   = $this->mikrotik->routerConfig($router);
        $input = $this->changeInput($request);
        $hash  = (string) $request->validate(
            ['hash' => ['required', 'string', 'size:40']],
            ['hash.*' => 'Pratinjau tidak valid. Ulangi pratinjau.'],
        )['hash'];
        $label = $input['type'] === 'radius' ? 'jalur RADIUS' : 'load balance';

        $pwd = (string) $request->input('current_password', '');
        if ($pwd === '' || ! Hash::check($pwd, Auth::user()->password)) {
            ActivityLogService::log(
                'password_confirm_failed',
                "Konfirmasi password gagal saat mengubah {$label} router {$cfg['name']}",
                'router',
                $router,
            );

            return response()->json(['ok' => false, 'message' => 'Password salah. Tidak ada yang diubah.'], 422);
        }

        try {
            $hasil = $this->config->apply($router, $input, $hash);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Router tidak dapat dihubungi: ' . $e->getMessage()], 502);
        }

        $ditolak = ($hasil['stale'] ?? false) || ($hasil['busy'] ?? false);

        if (! $ditolak && ($hasil['done'] ?? [])) {
            ActivityLogService::log(
                'loadbalance_update',
                "mengubah {$label} router {$cfg['name']}" . ($hasil['ok'] ? '' : ' (tidak tuntas)'),
                'router',
                $router,
                ['input' => $input, 'commands' => $hasil['done'], 'result' => $hasil['message']],
            );
        }

        return response()->json($hasil, $ditolak ? 409 : 200);
    }

    private function changeInput(Request $request): array
    {
        $data = $request->validate([
            'type'       => ['required', 'in:balance,radius'],
            'classifier' => ['required_if:type,balance', 'nullable', 'string', 'max:40'],
            'enabled'    => ['nullable', 'boolean'],
            'weights'    => ['required_if:type,balance', 'nullable', 'array', 'max:8'],
            'weights.*'  => ['integer', 'between:0,' . LoadBalanceConfigService::MAX_BUCKETS],
            'main'       => ['required_if:type,radius', 'nullable', 'string', 'max:64'],
        ], [
            'type.required'          => 'Jenis perubahan wajib diisi.',
            'type.in'                => 'Jenis perubahan tidak dikenal.',
            'classifier.required_if' => 'Classifier wajib dipilih.',
            'weights.required_if'    => 'Bobot tiap WAN wajib diisi.',
            'weights.*.integer'      => 'Bobot harus angka bulat.',
            'weights.*.between'      => 'Bobot tiap WAN harus 0 sampai ' . LoadBalanceConfigService::MAX_BUCKETS . '.',
            'main.required_if'       => 'Pilih WAN untuk main route.',
        ]);

        return $data['type'] === 'balance'
            ? ['type' => 'balance', 'classifier' => $data['classifier'], 'enabled' => (bool) ($data['enabled'] ?? true), 'weights' => $data['weights']]
            : ['type' => 'radius', 'main' => $data['main']];
    }

    public function index()
    {
        return view('load-balance.index');
    }

    public function routers()
    {
        $hasil  = [];
        $dicek  = 0;
        $gagal  = [];

        foreach ($this->mikrotik->routers() as $id => $cfg) {
            $dicek++;
            try {
                $det = $this->lb->detect($id);
                if ($det) {
                    $hasil[] = $this->kartu($id, $cfg, $det, true);
                }
            } catch (Exception $e) {
                $known = $this->lb->known($id);
                if ($known) {
                    $hasil[] = $this->kartu($id, $cfg, $known, false, $e->getMessage());
                } else {
                    $gagal[] = $cfg['name'];
                }
            }
        }

        return response()->json([
            'routers'     => $hasil,
            'checked'     => $dicek,
            'unreachable' => $gagal,
            'time'        => now()->format('H:i:s'),
        ]);
    }

    public function live(string $router)
    {
        $this->mikrotik->routerConfig($router);

        try {
            return response()->json($this->lb->live($router));
        } catch (Exception $e) {
            return response()->json(['online' => false, 'error' => $e->getMessage()]);
        }
    }

    public function history(string $router)
    {
        $this->mikrotik->routerConfig($router);

        try {
            $det = $this->lb->detect($router);
        } catch (Exception) {
            $det = $this->lb->known($router);
        }
        $ifaces = array_values(array_filter(array_column($det['wans'] ?? [], 'interface')));
        $from   = $ifaces ? $this->traffic->commonStart($router, $ifaces) : null;

        return response()->json($this->traffic->history($router, 7, $ifaces ?: null, $from));
    }

    private function kartu(string $id, array $cfg, array $det, bool $online, ?string $error = null): array
    {
        return [
            'id'          => $id,
            'name'        => $cfg['name'],
            'host'        => $cfg['host'],
            'online'      => $online,
            'error'       => $error,
            'method'      => $det['method'],
            'classifier'  => $det['classifier'],
            'denominator' => $det['denominator'],
            'enabled'     => $det['enabled'],
            'detected_at' => $det['detected_at'] ?? null,
            'wans'        => array_map(fn ($w) => [
                'interface'  => $w['interface'],
                'label'      => $w['label'],
                'gateway'    => $w['gateway'],
                'tables'     => $w['tables'],
                'target_pct' => $w['target_pct'],
            ], $det['wans']),
            'live_url'    => route('load-balance.live', $id),
            'history_url' => route('load-balance.history', $id),
            'config_url'  => route('load-balance.config', $id),
            'preview_url' => route('load-balance.preview', $id),
            'apply_url'   => route('load-balance.apply', $id),
            'router_url'  => route('routers.show', $id),
        ];
    }
}
