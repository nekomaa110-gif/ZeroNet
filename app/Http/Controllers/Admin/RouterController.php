<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use App\Services\RouterBackupService;
use App\Services\RouterKesehatanService;
use App\Services\RouterSnapshotService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RouterController extends Controller
{
    private const TEST_MAX_TIMEOUT = 8;

    public function __construct(private MikrotikService $svc) {}

    public function index()
    {
        $routers = collect($this->svc->routers())
            ->map(fn ($cfg, $id) => [
                'id'            => $id,
                'name'          => $cfg['name'],
                'host'          => $cfg['host'],
                'port'          => $cfg['port'],
                'user'          => $cfg['user'],
                'timeout'       => $cfg['timeout']       ?? 5,
                'wan_interface' => $cfg['wan_interface'] ?? 'ether1',
            ]);

        return view('routers.index', compact('routers'));
    }

    public function show(string $router)
    {
        $cfg = $this->svc->routerConfig($router);

        return view('routers.show', [
            'routerId'        => $router,
            'routerName'      => $cfg['name'],
            'routerHost'      => $cfg['host'],
            'routerInterface' => $cfg['wan_interface'] ?? 'ether1',
        ]);
    }

    public function stats(string $router, RouterSnapshotService $snap)
    {
        try {
            $snap->watch($router);
            $poller = $snap->pollerAlive();

            return response()->json([
                'online' => true,
                'stats'  => $poller ? $snap->stats($router) : $this->svc->stats($router),
                'source' => $poller ? 'poller' : 'direct',
            ]);
        } catch (Exception $e) {
            return response()->json(['online' => false, 'error' => $e->getMessage()]);
        }
    }

    public function kesehatan(Request $request, string $router, RouterKesehatanService $kesehatan): JsonResponse
    {
        $model = Router::where('slug', $router)->firstOrFail();

        try {
            return response()->json(['success' => true] + $kesehatan->script($model, $request->boolean('segar')));
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function testConnection(Request $request)
    {
        $data = $request->validate($this->connectionRules(requirePassword: false), $this->messages());

        $pass    = $data['password'] ?? '';
        $timeout = min((int) ($data['timeout'] ?? 5), self::TEST_MAX_TIMEOUT);

        if ($pass === '' && $request->filled('router')) {
            $cfg = $this->svc->routerConfig($request->input('router'));

            $sama = strcasecmp((string) $cfg['host'], $data['host']) === 0
                && (int) $cfg['port'] === (int) $data['port']
                && (string) $cfg['user'] === $data['username'];

            if (! $sama) {
                return response()->json([
                    'success' => false,
                    'error'   => 'IP, port, atau username berbeda dari data tersimpan. Isi password untuk menguji koneksi ini.',
                ], 422);
            }

            $pass = $cfg['pass'] ?? '';
        }

        try {
            $info = $this->svc->probe(
                $data['host'],
                $data['username'],
                $pass,
                (int) $data['port'],
                $timeout,
            );

            $interfaces = [];
            try {
                $interfaces = $this->svc->interfaces(
                    $data['host'], $data['username'], $pass,
                    (int) $data['port'], $timeout,
                );
            } catch (Exception) {
            }

            return response()->json(['success' => true, 'info' => $info, 'interfaces' => $interfaces]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(null, requirePassword: true), $this->messages());

        $router = Router::create([
            'slug'          => Router::makeSlug($data['name']),
            'name'          => $data['name'],
            'host'          => $data['host'],
            'port'          => (int) $data['port'],
            'username'      => $data['username'],
            'password'      => $data['password'],
            'timeout'       => (int) ($data['timeout'] ?? 5),
            'wan_interface' => $data['wan_interface'] ?? 'ether1',
            'sort_order'    => (int) (Router::max('sort_order') ?? 0) + 10,
        ]);

        MikrotikService::forgetRouterCache();

        ActivityLogService::log(
            action: 'create',
            description: "menambah router: {$router->name} ({$router->host}:{$router->port})",
            subjectType: 'router',
            subjectId: $router->slug,
        );

        return response()->json([
            'success'  => true,
            'message'  => "Router {$router->name} berhasil ditambahkan.",
            'redirect' => route('routers.siapkan', $router->slug),
        ]);
    }

    public function update(Request $request, Router $router)
    {
        $data = $request->validate($this->rules($router, requirePassword: false), $this->messages());

        $payload = [

            'slug'          => Router::makeSlug($data['name'], $router->id),
            'name'          => $data['name'],
            'host'          => $data['host'],
            'port'          => (int) $data['port'],
            'username'      => $data['username'],
            'timeout'       => (int) ($data['timeout'] ?? 5),
            'wan_interface' => $data['wan_interface'] ?? 'ether1',
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $router->update($payload);
        MikrotikService::forgetRouterCache();

        ActivityLogService::log(
            action: 'update',
            description: "mengubah router: {$router->name} ({$router->host}:{$router->port})",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: ['password_changed' => ! empty($data['password'])],
        );

        return response()->json([
            'success' => true,
            'message' => "Router {$router->name} berhasil diperbarui.",
        ]);
    }

    public function destroy(Router $router)
    {
        $name = $router->name;
        $slug = $router->slug;

        $router->delete();
        MikrotikService::forgetRouterCache();

        ActivityLogService::log(
            action: 'delete',
            description: "menghapus router: {$name}",
            subjectType: 'router',
            subjectId: $slug,
        );

        return response()->json([
            'success' => true,
            'message' => "Router {$name} dihapus dari panel.",
        ]);
    }

    private function connectionRules(bool $requirePassword): array
    {
        return [

            'host'          => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/'],
            'port'          => ['required', 'integer', 'between:1,65535'],
            'username'      => ['required', 'string', 'max:64'],
            'password'      => [$requirePassword ? 'required' : 'nullable', 'string', 'max:128'],
            'timeout'       => ['nullable', 'integer', 'between:1,30'],
            'wan_interface' => ['nullable', 'string', 'max:32'],
            'router'        => ['nullable', 'string', 'max:64'],
        ];
    }

    private function rules(?Router $router, bool $requirePassword): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('routers', 'name')->ignore($router?->id)],
            ...$this->connectionRules($requirePassword),
        ];
    }

    private function messages(): array
    {
        return [
            'name.required'          => 'Nama router wajib diisi.',
            'name.unique'            => 'Sudah ada router dengan nama itu.',
            'host.required'          => 'IP wajib diisi.',
            'host.regex'             => 'IP tidak valid. ',
            'port.required'          => 'Port API wajib diisi.',
            'port.integer'           => 'Port harus berupa angka.',
            'port.between'           => 'Port harus antara 1–65535.',
            'username.required'      => 'Username API wajib diisi.',
            'password.required'      => 'Password wajib diisi.',
            'timeout.between'        => 'Timeout harus antara 1–30 detik.',
            'wan_interface.max'      => 'Nama interface maksimal 32 karakter.',
        ];
    }

    public function reboot(string $router)
    {
        try {
            $cfg = $this->svc->routerConfig($router);
            $this->svc->reboot($router);
            return back()->with('success', "Router {$cfg['name']} sedang reboot...");
        } catch (Exception $e) {
            return back()->with('error', 'Gagal reboot: ' . $e->getMessage());
        }
    }

    public function backup(string $router)
    {
        try {
            $cfg     = $this->svc->routerConfig($router);
            $content = app(RouterBackupService::class)->unduh($router);
            $name    = Str::slug($cfg['name']) . '-' . date('Y-m-d-His') . '.backup';

            ActivityLogService::log(
                action: 'router_backup_download',
                description: "Download backup router: {$cfg['name']} ({$router})",
                subjectType: 'router',
                subjectId: $router,
                properties: ['filename' => $name, 'size' => strlen($content)],
            );

            return response($content, 200, [
                'Content-Type'        => 'application/octet-stream',
                'Content-Disposition' => "attachment; filename={$name}",
                'Content-Length'      => strlen($content),
            ]);
        } catch (Exception $e) {
            ActivityLogService::log(
                action: 'router_backup_failed',
                description: "Gagal download backup router: {$router}",
                subjectType: 'router',
                subjectId: $router,
                properties: ['error' => $e->getMessage()],
            );

            return back()->with('error', 'Gagal backup: ' . $e->getMessage());
        }
    }
}
