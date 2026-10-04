<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrintTemplate;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\ActivityLogService;
use App\Services\TemplateCetakService;
use App\Support\TemplateCetakBawaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TemplateCetakController extends Controller
{
    public function __construct(private TemplateCetakService $cetak) {}

    public function index()
    {
        return view('vouchers.templates', [
            'templates' => PrintTemplate::orderBy('name')->get(),
            'routers'   => Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name', 'template_cetak']),
            'pilihan'   => PrintTemplate::pilihan(),
            'variabel'  => collect(TemplateCetakService::VARIABEL)->map(fn ($arti, $nama) => ['tanda' => '{{' . $nama . '}}', 'arti' => $arti])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tpl = PrintTemplate::create($this->valid($request));

        return $this->tersimpan($tpl, 'dibuat');
    }

    public function update(Request $request, PrintTemplate $template): JsonResponse
    {
        $template->update($this->valid($request));

        return $this->tersimpan($template, 'diperbarui');
    }

    public function destroy(PrintTemplate $template): JsonResponse
    {
        $dipakai = Router::where('template_cetak', $template->kunci)->pluck('name');
        if ($dipakai->isNotEmpty()) {
            return response()->json(['success' => false, 'error' => 'Masih jadi default ' . $dipakai->implode(', ') . '. Ganti default router itu dulu.'], 422);
        }

        $template->delete();
        $this->catat('voucher_template_hapus', "Menghapus template cetak {$template->name}", $template);

        return response()->json(['success' => true, 'message' => "Template {$template->name} dihapus."]);
    }

    public function pulihkan(PrintTemplate $template): JsonResponse
    {
        $asli = TemplateCetakBawaan::semua()[$template->bawaan] ?? null;
        if (! $asli) {
            return response()->json(['success' => false, 'error' => 'Template ini bukan bawaan, tidak ada versi asli.'], 422);
        }

        $template->update($asli);

        return $this->tersimpan($template, 'dikembalikan ke versi asli');
    }

    public function pratinjau(Request $request): JsonResponse
    {
        $data = $request->validate([
            'html'    => ['required', 'string', 'max:' . TemplateCetakService::MAKS_PANJANG],
            'per_row' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $batch = new VoucherBatch(['code' => 'up-123-09.27.26-contoh', 'profile' => '4-JAM', 'validity' => '1d', 'price' => 5000, 'limit_uptime' => '4h']);
        $contoh = collect([
            ['username' => 'ab3k7', 'password' => 'ab3k7'],
            ['username' => 'hm4x2', 'password' => 'hm4x2'],
            ['username' => '5Kx7m2', 'password' => '4821'],
            ['username' => '5Kp9d4', 'password' => '7362'],
        ])->map(fn ($v) => new Voucher($v + ['profile' => '4-JAM', 'price' => 5000, 'limit_uptime' => '4h', 'comment' => 'up-123-09.27.26-contoh']));

        $hasil = $this->cetak->render($data['html'], $contoh, $batch);

        return response()->json([
            'success' => true,
            'html'    => view('vouchers._pratinjau-template', $hasil + ['perRow' => (int) $data['per_row']])->render(),
        ] + $this->cetak->periksa($data['html']));
    }

    public function aturDefault(Request $request, Router $router): JsonResponse
    {
        $data = $request->validate([
            'template' => ['nullable', 'string', Rule::in(array_keys(PrintTemplate::pilihan()))],
        ]);

        $router->update(['template_cetak' => $data['template'] ?? null]);

        ActivityLogService::log(
            action: 'voucher_template_default',
            description: "Template cetak default {$router->name}: " . (PrintTemplate::pilihan()[$data['template'] ?? ''] ?? 'bawaan panel'),
            subjectType: 'router',
            subjectId: $router->slug,
        );

        return response()->json(['success' => true, 'message' => "Default cetak {$router->name} disimpan."]);
    }

    private function valid(Request $request): array
    {
        return $request->validate([
            'name'    => ['required', 'string', 'max:60'],
            'per_row' => ['required', 'integer', 'min:0', 'max:10'],
            'html'    => ['required', 'string', 'max:' . TemplateCetakService::MAKS_PANJANG],
        ], [
            'html.max' => 'Template terlalu panjang (maksimal ' . number_format(TemplateCetakService::MAKS_PANJANG, 0, ',', '.') . ' karakter).',
        ]);
    }

    private function tersimpan(PrintTemplate $tpl, string $aksi): JsonResponse
    {
        $cek = $this->cetak->periksa($tpl->html);
        $this->catat('voucher_template_simpan', "Template cetak {$tpl->name} {$aksi}", $tpl, $cek);

        return response()->json([
            'success'  => true,
            'message'  => "Template {$tpl->name} {$aksi}.",
            'template' => $tpl->only(['id', 'name', 'per_row', 'html', 'bawaan']),
        ] + $cek);
    }

    private function catat(string $aksi, string $keterangan, PrintTemplate $tpl, array $properti = []): void
    {
        ActivityLogService::log(
            action: $aksi,
            description: $keterangan,
            subjectType: 'print_template',
            subjectId: (string) $tpl->id,
            properties: $properti,
        );
    }
}
