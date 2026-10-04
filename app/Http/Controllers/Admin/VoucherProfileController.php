<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\PackageService;
use App\Services\VoucherRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class VoucherProfileController extends Controller
{
    public function __construct(private VoucherRouterService $routers) {}

    public function options(Router $router): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'pools'   => $this->routers->pools($router),
                'queues'  => $this->routers->parentQueues($router),
                'usage'   => $this->routers->profileUsage($router),
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }
    }

    public function store(Request $request, Router $router): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $hasil = $this->routers->createProfile($router, $data);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        return $this->selesai($router, $hasil, "Profil {$data['name']} dibuat di router {$router->name}.");
    }

    public function update(Request $request, Router $router): JsonResponse
    {
        $data = $this->validated($request, wajibAsal: true);

        try {
            $hasil = $this->routers->updateProfile($router, $data['original_name'], $data);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        $pesan = "Profil {$data['name']} diperbarui di router {$router->name}.";
        if (! empty($hasil['renamed'])) {
            $pesan .= " Nama lama: {$data['original_name']} (kartu yang memakainya ikut pindah sendiri).";
        }
        if (($hasil['scheduler_yatim_dicabut'] ?? 0) > 0) {
            $pesan .= " {$hasil['scheduler_yatim_dicabut']} scheduler lama dengan nama profil sebelumnya ikut dicabut.";
        }

        return $this->selesai($router, $hasil, $pesan);
    }

    public function destroy(Request $request, Router $router): JsonResponse
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:64']],
            ['name.required' => 'Profil mana yang mau dihapus?'],
        );

        try {
            $hasil = $this->routers->deleteProfile($router, $data['name']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        $pesan = "Profil {$data['name']} dihapus dari router {$router->name}.";
        if (($hasil['scheduler_yatim_dicabut'] ?? 0) > 0) {
            $pesan .= " {$hasil['scheduler_yatim_dicabut']} scheduler lama yang senama ikut dicabut.";
        }

        return $this->selesai($router, $hasil, $pesan);
    }

    private function validated(Request $request, bool $wajibAsal = false): array
    {
        $data = $request->validate([

            'name'          => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/'],
            'original_name' => [$wajibAsal ? 'required' : 'nullable', 'string', 'max:64'],
            'shared_users'  => ['required', 'integer', 'min:1', 'max:100'],

            'rate_limit'    => ['nullable', 'string', 'max:120', 'regex:%^[0-9]+[kKmMgG]?/[0-9]+[kKmMgG]?( [0-9kKmMgG/ ]+)?$%'],
            'address_pool'  => ['nullable', 'string', 'max:64'],
            'parent_queue'  => ['nullable', 'string', 'max:64'],
            'idle_timeout'  => ['nullable', 'string', 'max:20', 'regex:/^(none|(\d+[wdhms])+)$/'],
            'mode'          => ['required', Rule::in(['off', 'ntf', 'rem'])],
            'record'        => ['nullable', 'boolean'],
            'lock'          => ['nullable', 'boolean'],
            'validity'      => ['nullable', 'string', 'max:20', 'regex:/^(\d+[wdhms])+$/'],
            'price'         => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'sprice'        => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [
            'name.required'       => 'Nama profil wajib diisi.',
            'name.regex'          => 'Nama profil hanya boleh huruf, angka, titik, garis bawah, dan strip. Tanpa spasi. '
                                     . 'Nama ini ikut ditanam ke script pengawas di router.',
            'shared_users.min'    => 'Shared users minimal 1.',
            'shared_users.max'    => 'Shared users maksimal 100.',
            'rate_limit.regex'    => 'Format rate limit salah. Contoh: 4M/8M atau 512k/1M.',
            'idle_timeout.regex'  => 'Format idle timeout salah. Contoh: 5m, 1h, atau none.',
            'validity.regex'      => 'Format masa aktif salah. Contoh: 1d, 5h, 7d.',
        ]);

        if ($data['mode'] !== 'off' && empty($data['validity'])) {
            abort(response()->json([
                'success' => false,
                'errors'  => ['validity' => ['Masa aktif wajib diisi kalau kedaluwarsanya diawasi.']],
            ], 422));
        }

        return $data;
    }

    private function selesai(Router $router, array $hasil, string $pesan): JsonResponse
    {
        PackageService::lupakanProfilRouter($router->slug);

        ActivityLogService::log(
            action: 'voucher_profile_' . ($hasil['aksi'] ?? 'ubah'),
            description: $pesan,
            subjectType: 'router',
            subjectId: $router->slug,
            properties: $hasil,
        );

        if (! empty($hasil['warning'])) {
            $pesan .= ' ' . $hasil['warning'];
        }

        return response()->json(['success' => true, 'message' => $pesan, 'hasil' => $hasil]);
    }
}
