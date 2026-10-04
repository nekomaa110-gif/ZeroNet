<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PackageRequest;
use App\Models\Package;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\PackageService;
use App\Services\VoucherRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class PackageController extends Controller
{
    public function __construct(private PackageService $service) {}

    public const PROFIL_CACHE_DETIK = 300;

    public function index(): View
    {
        return view('packages.index', [
            'packages' => $this->service->all(),
            'routers'  => Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name']),
        ]);
    }

    public function profilRouter(Request $request, Router $router, VoucherRouterService $routers): JsonResponse
    {
        if ($request->boolean('segar')) {
            PackageService::lupakanProfilRouter($router->slug);
        }

        try {
            $profil = Cache::remember(PackageService::kunciProfilRouter($router->slug), self::PROFIL_CACHE_DETIK, fn () => collect($routers->profiles($router))
                ->map(fn ($p) => [
                    'name'       => $p['name'],
                    'rate'       => $p['rate_limit'],
                    'shared'     => $p['shared_users'],
                    'pool'       => $p['address_pool'],
                    'parent'     => $p['parent_queue'],
                    'idle'       => $p['idle_timeout'],
                    'mode'       => str_starts_with($p['expmode'], 'rem') ? 'rem' : ($p['expmode'] !== '' ? 'ntf' : 'off'),
                    'record'     => $p['record'],
                    'validity'   => $p['validity'],
                    'price'      => $p['price'],
                    'sprice'     => $p['sprice'],
                    'lock'       => $p['lock'],
                    'managed'    => $p['managed'],
                    'has_script' => $p['has_script'],
                    'locked'     => VoucherRouterService::profilTerlindungi($p['name']),
                ])
                ->values()
                ->all());

            return response()->json(['success' => true, 'profiles' => $profil]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'error' => "{$router->name} tidak terjangkau."], 503);
        } finally {
            $routers->disconnect($router);
        }
    }

    public function create(): View
    {
        return view('packages.form', [
            'package'   => null,
            'isUpdate'  => false,
            'presets'   => PackageService::ATTRIBUTE_PRESETS,
            'operators' => PackageService::OPERATORS,
            'targets'   => PackageService::TARGET_TABLES,
        ]);
    }

    public function simpanRadius(Request $request): JsonResponse
    {
        $data = $request->validate([
            'groupname'          => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'baru'               => ['nullable', 'boolean'],
            'profil'             => ['required', 'string', 'max:64'],
            'perangkat'          => ['nullable', 'integer', 'min:1', 'max:100'],
            'display_name'       => ['nullable', 'string', 'max:100'],
            'speed_label'        => ['nullable', 'string', 'max:50'],
            'description'        => ['nullable', 'string', 'max:255'],
            'is_active'          => ['nullable', 'boolean'],
            'is_public'          => ['nullable', 'boolean'],
            'price'              => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'show_price'         => ['nullable', 'boolean'],
            'validity_days'      => ['nullable', 'integer', 'min:1', 'max:3650'],
            'public_description' => ['nullable', 'string', 'max:255'],
            'sort_order'         => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'groupname.required' => 'Nama paket wajib diisi.',
            'groupname.regex'    => 'Nama paket hanya boleh huruf, angka, strip, titik, dan garis bawah.',
            'profil.required'    => 'Pilih profil router yang dipakai paket ini.',
            'validity_days.min'  => 'Masa di website minimal 1 hari.',
        ]);

        if (! empty($data['baru']) && $this->service->exists($data['groupname'])) {
            return response()->json(['success' => false, 'errors' => ['groupname' => ['Nama paket sudah dipakai.']]], 422);
        }

        $package = $this->service->simpanRadius($data);

        ActivityLogService::log('update', "menyimpan paket RADIUS {$package->groupname} (profil {$data['profil']})", 'package', $package->groupname);

        return response()->json([
            'success' => true,
            'message' => "Paket RADIUS {$package->groupname} disimpan.",
            'paket'   => $this->service->all()->values(),
        ]);
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        $package = $this->service->create($request->validated());

        ActivityLogService::log('create', "membuat paket: {$package->groupname}", 'package', $package->groupname);

        return redirect()
            ->route('packages.index')
            ->with('success', "Paket \"{$package->groupname}\" berhasil dibuat.");
    }

    public function edit(Package $package): View
    {
        return view('packages.form', [
            'package'   => $this->service->find($package),
            'isUpdate'  => true,
            'presets'   => PackageService::ATTRIBUTE_PRESETS,
            'operators' => PackageService::OPERATORS,
            'targets'   => PackageService::TARGET_TABLES,
        ]);
    }

    public function update(PackageRequest $request, Package $package): RedirectResponse
    {
        $this->service->update($package, $request->validated());

        ActivityLogService::log('update', "mengupdate paket: {$package->groupname}", 'package', $package->groupname);

        return redirect()
            ->route('packages.index')
            ->with('success', "Paket \"{$package->groupname}\" berhasil diperbarui.");
    }

    public function toggle(Package $package): RedirectResponse
    {
        $this->service->toggle($package);
        $status = $package->is_active ? 'diaktifkan' : 'dinonaktifkan';

        ActivityLogService::log('update', "{$status} paket: {$package->groupname}", 'package', $package->groupname);

        return back()->with('success', "Paket \"{$package->groupname}\" berhasil {$status}.");
    }

    public function destroy(Package $package): RedirectResponse
    {
        $groupname = $package->groupname;
        $this->service->delete($package);

        ActivityLogService::log('delete', "menghapus paket: {$groupname}", 'package', $groupname);

        return redirect()
            ->route('packages.index')
            ->with('success', "Paket \"{$groupname}\" berhasil dihapus.");
    }

    public function legacyEdit(string $groupname): RedirectResponse
    {
        if (! $this->service->existsInRadius($groupname)) {
            abort(404, 'Profil tidak ditemukan.');
        }

        $package = Package::where('groupname', $groupname)->first()
            ?? $this->service->importFromRadius($groupname);

        ActivityLogService::log(
            'create',
            "mengimpor profil dari panel lama: {$groupname}",
            'package',
            $groupname,
        );

        return redirect()->route('packages.edit', $package);
    }
}
