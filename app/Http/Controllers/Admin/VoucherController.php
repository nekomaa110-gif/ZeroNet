<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncVoucherBatch;
use App\Models\PrintTemplate;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\ActivityLogService;
use App\Services\TemplateCetakService;
use App\Services\VoucherPushService;
use App\Services\VoucherRouterService;
use App\Services\VoucherService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class VoucherController extends Controller
{
    public const HAPUS_MASSAL_MAKS = 1000;

    public function __construct(
        private VoucherService $vouchers,
        private VoucherRouterService $routers,
    ) {}

    public function index(Request $request)
    {
        $routers = Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name']);

        $routerId = $routers->firstWhere('slug', $request->query('router'))?->id;

        $query = VoucherBatch::query()
            ->with('creator:id,name,username')
            ->when($routerId, fn ($q) => $q->where('router_id', $routerId))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(trim((string) $request->query('q')), function ($q, $cari) {
                $q->where(function ($w) use ($cari) {
                    $w->where('code', 'like', "%{$cari}%")
                      ->orWhere('label', 'like', "%{$cari}%")
                      ->orWhere('profile', 'like', "%{$cari}%");
                });
            })
            ->latest('id');

        $batches = (clone $query)->paginate(15)->withQueryString();
        if ($batches->isEmpty() && $batches->currentPage() > 1) {
            $batches = $query->paginate(15, page: $batches->lastPage())->withQueryString();
        }

        $data = [
            'batches'   => $batches,
            'pemakaian' => $request->user()?->isAdmin() ? $this->pemakaianBatch($batches->pluck('id')->all()) : collect(),
            'routers'   => $routers,
            'stats'     => $this->vouchers->stats($routerId),
            'filters'   => [
                'router' => (string) $request->query('router', ''),
                'status' => (string) $request->query('status', ''),
                'q'      => (string) $request->query('q', ''),
            ],
        ];

        if ($request->ajax() || $request->wantsJson()) {
            return view('vouchers._batches', $data + ['ringkasanLive' => true]);
        }

        return view('vouchers.index', $data + [
            'charsets'   => config('voucher.charsets'),
            'maxPerBatch' => (int) config('voucher.max_per_batch', 1000),
            'defaultQty' => (int) config('voucher.default_qty', 100),
        ]);
    }

    private function pemakaianBatch(array $ids)
    {
        return Voucher::query()
            ->whereIn('batch_id', $ids)
            ->selectRaw(
                "batch_id, sum(status = 'ready' and sync_status = 'success') as siap, sum((" . Voucher::statusEfektifSql() . ") = 'active') as aktif",
                Voucher::ikatanStatusEfektif(),
            )
            ->groupBy('batch_id')
            ->get()
            ->keyBy('batch_id');
    }

    public function routerOptions(Router $router): JsonResponse
    {
        try {
            $limits = $this->routers->commonLimits($router);

            $profiles = collect($this->routers->profiles($router))

                ->map(fn ($p) => [
                    'name'     => $p['name'],
                    'validity' => $p['validity'],
                    'price'    => $p['price'],
                    'rate'     => $p['rate_limit'],
                    'expmode'  => $p['expmode'],
                    'managed'  => $p['managed'],
                    'voucher'  => $p['validity'] !== '',
                    'limit'    => $limits[$p['name']] ?? '',
                ])
                ->values();

            return response()->json([
                'success'  => true,
                'profiles' => $profiles,
                'servers'  => $this->routers->servers($router),
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'router'          => ['required', 'string', Rule::exists('routers', 'slug')],
            'profile'         => ['required', 'string', 'max:64'],
            'server'          => ['nullable', 'string', 'max:64'],
            'quantity'        => ['required', 'integer', 'min:1', 'max:' . config('voucher.max_per_batch', 1000)],
            'mode'            => ['required', Rule::in(['vc', 'up'])],
            'charset'         => ['required', Rule::in(array_keys(config('voucher.charsets')))],
            'code_length'     => ['required', 'integer', 'min:3', 'max:12'],
            'password_length' => ['nullable', 'integer', 'min:3', 'max:8'],
            'prefix'          => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'suffix'          => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'limit_uptime'    => ['nullable', 'string', 'max:20', 'regex:/^(\d+[wdhms])+$/'],
            'limit_mb'        => ['nullable', 'integer', 'min:0', 'max:1048576'],
            'label'           => ['nullable', 'string', 'max:20'],
        ], [
            'router.required'       => 'Pilih router tujuan dulu.',
            'router.exists'         => 'Router itu tidak ada di panel.',
            'profile.required'      => 'Pilih profil voucher dulu.',
            'quantity.required'     => 'Jumlah voucher wajib diisi.',
            'quantity.max'          => 'Maksimal :max voucher sekali buat.',
            'code_length.min'       => 'Panjang kode minimal 3 karakter.',
            'code_length.max'       => 'Panjang kode maksimal 12 karakter.',
            'prefix.regex'          => 'Prefix hanya boleh huruf dan angka.',
            'suffix.regex'          => 'Suffix hanya boleh huruf dan angka.',
            'limit_uptime.regex'    => 'Format jatah jam salah. Contoh: 5h, 1d, 1h30m.',
        ]);

        $router = Router::where('slug', $data['router'])->firstOrFail();

        $data['limit_bytes'] = ! empty($data['limit_mb']) ? (int) $data['limit_mb'] * 1024 * 1024 : null;

        try {
            $batch = $this->vouchers->generate($router, $data, auth()->id());
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        ActivityLogService::log(
            action: 'voucher_generate',
            description: "Membuat {$batch->quantity} voucher {$batch->profile} di {$batch->router_label} (batch {$batch->code})",
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: [
                'quantity' => $batch->quantity,
                'profile'  => $batch->profile,
                'router'   => $router->slug,
            ],
        );

        return response()->json([
            'success'  => true,
            'batch'    => $batch->id,
            'message'  => "{$batch->quantity} voucher dibuat. Sedang ditulis ke router…",
            'progress' => route('vouchers.progress', $batch),
            'redirect' => route('vouchers.show', $batch),
        ]);
    }

    public function storeCepat(Request $request, VoucherPushService $push): JsonResponse
    {
        $data = $request->validate([
            'router'       => ['required', 'string', Rule::exists('routers', 'slug')],
            'profile'      => ['required', 'string', 'max:64'],
            'server'       => ['nullable', 'string', 'max:64'],
            'username'     => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password'     => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'comment'      => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ._-]*$/'],
            'limit_uptime' => ['nullable', 'string', 'max:20', 'regex:/^(\d+[wdhms])+$/'],
            'limit_mb'     => ['nullable', 'integer', 'min:0', 'max:1048576'],
        ], [
            'username.required'  => 'Nama voucher wajib diisi.',
            'username.regex'     => 'Nama hanya boleh huruf, angka, titik, garis bawah, @, dan strip.',
            'password.regex'     => 'Password hanya boleh huruf, angka, titik, garis bawah, @, dan strip.',
            'comment.regex'      => 'Catatan hanya boleh huruf, angka, spasi, titik, garis bawah, dan strip.',
            'limit_uptime.regex' => 'Format jatah jam salah. Contoh: 5h, 1d, 1h30m.',
        ]);

        $router = Router::where('slug', $data['router'])->firstOrFail();
        $data['limit_bytes'] = ! empty($data['limit_mb']) ? (int) $data['limit_mb'] * 1024 * 1024 : null;

        try {
            $batch = $this->vouchers->tambahSatu($router, $data, auth()->id());
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        try {
            $hasil = $push->push($batch);
        } catch (Throwable) {
            SyncVoucherBatch::dispatch($batch->id);
            $hasil = null;
        }

        $voucher = $batch->vouchers()->first();

        ActivityLogService::log(
            action: 'voucher_generate',
            description: "Membuat 1 voucher manual {$voucher->username} ({$batch->profile}) di {$batch->router_label}",
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: ['quantity' => 1, 'profile' => $batch->profile, 'router' => $router->slug, 'username' => $voucher->username],
        );

        $terkirim = $hasil !== null && $hasil['ok'] === 1;

        return response()->json([
            'success'  => $terkirim || $hasil === null,
            'error'    => $hasil !== null && ! $terkirim ? ($voucher->sync_error ?: 'Router menolak voucher ini.') : null,
            'message'  => $terkirim
                ? "Voucher {$voucher->username} sudah ada di router."
                : "Voucher {$voucher->username} dibuat. Router sedang sibuk, dikirim lewat antrean.",
            'redirect' => route('vouchers.show', $batch),
        ], $terkirim || $hasil === null ? 200 : 422);
    }

    public function show(Request $request, VoucherBatch $batch)
    {
        $saring   = $request->only(['status', 'sync', 'q']);
        $vouchers = $this->saringKartu($batch->vouchers(), $saring)
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $data = [
            'batch'    => $batch->load('creator:id,name,username', 'router:id,slug,name'),
            'vouchers' => $vouchers,

            'ringkas'  => $batch->vouchers()
                ->selectRaw(Voucher::statusEfektifSql() . ' as s, count(*) as n', Voucher::ikatanStatusEfektif())
                ->groupBy('s')
                ->pluck('n', 's'),
            'filters'  => [
                'status' => (string) $request->query('status', ''),
                'sync'   => (string) $request->query('sync', ''),
                'q'      => (string) $request->query('q', ''),
            ],
            'rincian'  => $request->user()?->isAdmin()
                ? $this->saringKartu($batch->vouchers(), $saring)
                    ->selectRaw(Voucher::statusEfektifSql() . ' as s, count(*) as n', Voucher::ikatanStatusEfektif())
                    ->groupBy('s')
                    ->pluck('n', 's')
                    ->map(fn ($n) => (int) $n)
                : collect(),
        ];

        if ($request->ajax() || $request->wantsJson()) {
            return view('vouchers._list', $data);
        }

        return view('vouchers.show', $data);
    }

    public function progress(VoucherBatch $batch): JsonResponse
    {
        return response()->json([
            'status'   => $batch->status,
            'label'    => $batch->status_label,
            'quantity' => $batch->quantity,
            'synced'   => $batch->synced_count,
            'failed'   => $batch->failed_count,
            'progress' => $batch->progress,
            'error'    => $batch->error,
            'done'     => in_array($batch->status, ['success', 'partial', 'failed'], true),
        ]);
    }

    public function resync(VoucherBatch $batch): JsonResponse
    {
        if (! $batch->router) {
            return response()->json(['success' => false, 'error' => 'Router batch ini sudah dihapus dari panel.'], 422);
        }

        $batch->update(['status' => 'pending', 'error' => null]);
        SyncVoucherBatch::dispatch($batch->id);

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi ulang dijalankan. Voucher yang sudah masuk tidak ditulis dua kali.',
        ]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $router = Router::where('slug', $request->input('router'))->firstOrFail();

        try {
            $angka = $this->vouchers->reconcile($router);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->routers->disconnect($router);
        }

        return response()->json([
            'success' => true,
            'message' => "Diperiksa {$angka['diperiksa']} kartu: {$angka['aktif']} terpakai, "
                . "{$angka['habis']} habis, {$angka['hilang']} tidak ada lagi di router.",
            'angka'   => $angka,
        ]);
    }

    public function destroy(Request $request, VoucherBatch $batch)
    {
        $hapusRouter = $request->boolean('remove_from_router');
        $dicabut     = null;
        $tolak       = fn (string $pesan, string $sebab, int $kode) => $request->expectsJson()
            ? response()->json(['success' => false, 'error' => $pesan, 'sebab' => $sebab], $kode)
            : back()->with('error', $pesan);

        if ($hapusRouter && ! Hash::check((string) $request->input('current_password', ''), (string) $request->user()->password)) {
            ActivityLogService::log(
                action: 'password_confirm_failed',
                description: "Verifikasi password gagal, tidak bisa menghapus batch {$batch->code} dari router",
                subjectType: 'user',
                subjectId: (string) $request->user()->id,
                properties: ['ip' => $request->ip(), 'intent' => 'hapus batch dari router'],
            );

            return $tolak("Password salah. Batch {$batch->code} tidak dihapus.", 'password', 422);
        }

        if ($batch->sedangSinkron()) {
            return $tolak("Batch {$batch->code} sedang disinkronkan ke router. Tunggu sampai selesai, baru hapus.", 'sinkron', 409);
        }

        if ($hapusRouter && $batch->router) {
            try {
                $names = $batch->vouchers()
                    ->where(fn ($q) => $q->whereNull('sync_error')->orWhere('sync_error', 'not like', VoucherService::BENTROK_PREFIX . '%'))
                    ->pluck('username')->all();
                $dicabut = $this->routers->removeUsers($batch->router, $names)['removed'];
            } catch (Exception $e) {
                return $tolak('Gagal menghapus dari router: ' . $e->getMessage(), 'router', 422);
            } finally {
                $this->routers->disconnect($batch->router);
            }
        }

        $catatan = $dicabut === null ? '' : " {$dicabut} kartu ikut dihapus dari router.";
        $code    = $batch->code;
        $batch->delete();

        ActivityLogService::log(
            action: 'voucher_batch_delete',
            description: "Menghapus batch voucher {$code}." . $catatan,
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: ['remove_from_router' => $hapusRouter],
        );

        $pesan = "Batch {$code} dihapus dari panel." . $catatan;

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $pesan, 'dicabut' => (int) $dicabut])
            : redirect()->route('vouchers.index')->with('success', $pesan);
    }

    public function print(Request $request, VoucherBatch $batch, TemplateCetakService $cetak)
    {
        $templates = config('voucher.print_templates');
        $pilihan   = PrintTemplate::pilihan();
        $template  = (string) $request->query('t', $batch->router?->template_cetak ?: 'v4');

        if (! isset($pilihan[$template])) {
            $template = 'v4';
        }

        $vouchers = $batch->vouchers()
            ->when($request->boolean('belum'), fn ($q) => $q->whereNull('printed_at'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->get();

        $nonce = Str::random(32);
        $data  = ['batch' => $batch, 'vouchers' => $vouchers, 'tplKey' => $template, 'pilihan' => $pilihan, 'nonce' => $nonce];

        if (isset($templates[$template])) {
            $halaman = response()->view('vouchers.print', $data + ['tpl' => $templates[$template], 'card' => config('voucher.card')]);
        } else {
            $tpl     = PrintTemplate::findOrFail((int) substr($template, 1));
            $hasil   = $cetak->render($tpl->html, $vouchers, $batch);
            $halaman = response()->view('vouchers.print-template', $data + ['gaya' => $hasil['gaya'], 'kartu' => $hasil['kartu'], 'perRow' => $tpl->per_row]);
        }

        return $halaman->header('Content-Security-Policy', TemplateCetakService::csp($nonce));
    }

    public function markPrinted(Request $request, VoucherBatch $batch): JsonResponse
    {
        $ids = $request->validate([
            'ids'   => ['required', 'array', 'max:2000'],
            'ids.*' => ['integer'],
        ])['ids'];

        $jumlah = $batch->vouchers()->whereIn('id', $ids)->whereNull('printed_at')->update(['printed_at' => now()]);
        $batch->update(['printed_at' => now()]);

        return response()->json(['success' => true, 'ditandai' => $jumlah]);
    }

    public function export(VoucherBatch $batch)
    {
        $nama = 'voucher-' . $batch->code . '.csv';

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['username', 'password', 'profil', 'harga', 'status', 'sinkron', 'dipakai', 'kedaluwarsa']);

            $batch->vouchers()->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $v) {
                    fputcsv($out, [
                        $v->username,
                        $v->password,
                        $v->profile,
                        $v->price,
                        $v->status_label,
                        $v->sync_status,
                        $v->activated_at?->format('Y-m-d H:i'),
                        $v->expired_at?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroyMany(Request $request, VoucherBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'semua'    => ['nullable', 'boolean'],
            'ids'      => ['nullable', 'array', 'max:' . self::HAPUS_MASSAL_MAKS],
            'ids.*'    => ['integer'],
            'jumlah'   => ['nullable', 'integer', 'min:1'],
            'status'   => ['nullable', 'string', 'max:20'],
            'sync'     => ['nullable', 'string', 'max:20'],
            'q'        => ['nullable', 'string', 'max:64'],
        ]);
        $semua = (bool) ($data['semua'] ?? false);
        $ids   = array_values(array_unique($data['ids'] ?? []));

        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            ActivityLogService::log(
                action: 'password_confirm_failed',
                description: "Verifikasi password gagal, tidak bisa menghapus kartu batch {$batch->code}",
                subjectType: 'user',
                subjectId: (string) $request->user()->id,
                properties: ['ip' => $request->ip(), 'intent' => 'hapus massal kartu'],
            );

            return response()->json(['success' => false, 'error' => 'Password salah.'], 422);
        }

        if ($semua ? empty($data['jumlah']) : ! $ids) {
            return response()->json(['success' => false, 'error' => 'Belum ada kartu yang dipilih.'], 422);
        }

        if ($batch->sedangSinkron()) {
            return response()->json(['success' => false, 'error' => 'Batch ini sedang disinkronkan ke router. Tunggu sampai selesai.'], 409);
        }

        $kartu = ($semua ? $this->saringKartu($batch->vouchers(), $data) : $batch->vouchers()->whereIn('id', $ids))
            ->get(['id', 'username', 'status', 'expired_at', 'sync_status', 'sync_error']);
        $jumlah = $kartu->count();

        if ($jumlah > self::HAPUS_MASSAL_MAKS) {
            return response()->json(['success' => false, 'error' => 'Maksimal ' . self::HAPUS_MASSAL_MAKS . ' kartu sekali hapus.'], 422);
        }

        if ($semua && $jumlah !== (int) $data['jumlah']) {
            return response()->json(['success' => false, 'error' => "Jumlah kartu yang cocok berubah dari {$data['jumlah']} menjadi {$jumlah}. Muat ulang halaman lalu ulangi."], 409);
        }

        if (! $semua && $jumlah !== count($ids)) {
            return response()->json(['success' => false, 'error' => 'Sebagian kartu yang dipilih tidak ada di batch ini. Muat ulang halaman lalu ulangi.'], 422);
        }

        $bentrok = fn (Voucher $v) => str_starts_with((string) $v->sync_error, VoucherService::BENTROK_PREFIX);
        $cabut   = $kartu->reject($bentrok)->whereIn('sync_status', ['success', 'kirim'])->pluck('username')->all();
        $hasil   = ['removed' => 0, 'missing' => 0];

        if ($batch->router && $cabut) {
            try {
                $hasil = $this->routers->removeUsers($batch->router, $cabut);
            } catch (Exception $e) {
                return response()->json(['success' => false, 'error' => "Gagal menghapus dari router {$batch->router->name}: {$e->getMessage()} Tidak ada kartu yang dihapus dari panel."], 422);
            } finally {
                $this->routers->disconnect($batch->router);
            }
        }

        $rincian = $kartu->countBy(fn (Voucher $v) => $v->status_efektif)->sortKeys()->all();

        DB::transaction(function () use ($batch, $kartu, $jumlah) {
            Voucher::whereIn('id', $kartu->pluck('id'))->delete();
            $batch->forceFill(['quantity' => max(0, (int) $batch->quantity - $jumlah)])->save();
        });
        $this->vouchers->rangkumBatch($batch->refresh());

        ActivityLogService::log(
            action: 'voucher_delete_many',
            description: "Menghapus {$jumlah} kartu dari batch {$batch->code}" . ($batch->router ? " dan dari router {$batch->router->name}." : '.'),
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: [
                'jumlah'              => $jumlah,
                'mode'                => $semua ? 'filter' : 'pilihan',
                'rincian'             => $rincian,
                'filter'              => $semua ? array_filter(array_intersect_key($data, ['status' => 1, 'sync' => 1, 'q' => 1])) : null,
                'router'              => $batch->router?->slug,
                'dicabut_router'      => $hasil['removed'],
                'tidak_ada_di_router' => $hasil['missing'],
                'dilewati_bentrok'    => $kartu->filter($bentrok)->count(),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => "{$jumlah} kartu dihapus dari panel" . ($hasil['removed'] ? ", {$hasil['removed']} di antaranya dicabut dari router." : '.'),
            'jumlah'  => $jumlah,
        ]);
    }

    private function saringKartu($query, array $saring)
    {
        return $query
            ->when($saring['status'] ?? null, fn ($q, $s) => $q->status($s))
            ->when($saring['sync'] ?? null, fn ($q, $s) => $q->where('sync_status', $s))
            ->when(trim((string) ($saring['q'] ?? '')), fn ($q, $cari) => $q->where('username', 'like', "%{$cari}%"));
    }

    public function destroyVoucher(Request $request, Voucher $voucher): JsonResponse
    {
        if ($voucher->batch?->sedangSinkron()) {
            return response()->json(['success' => false, 'error' => 'Batch kartu ini sedang disinkronkan ke router. Tunggu sampai selesai.'], 409);
        }

        $hapusRouter = $request->boolean('remove_from_router') && $voucher->router
            && ! str_starts_with((string) $voucher->sync_error, VoucherService::BENTROK_PREFIX);

        if ($hapusRouter) {
            try {
                $this->routers->removeUsers($voucher->router, [$voucher->username]);
            } catch (Exception $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            } finally {
                $this->routers->disconnect($voucher->router);
            }
        }

        $batch = $voucher->batch;
        $voucher->delete();

        if ($batch) {
            DB::table('voucher_batches')->where('id', $batch->id)->where('quantity', '>', 0)->decrement('quantity');
            $this->vouchers->rangkumBatch($batch->refresh());
        }

        ActivityLogService::log(
            action: 'voucher_delete',
            description: "Menghapus voucher {$voucher->username}" . ($batch ? " dari batch {$batch->code}" : '')
                . ($hapusRouter ? " dan dari router {$voucher->router->name}." : '.'),
            subjectType: 'voucher',
            subjectId: (string) $voucher->id,
            properties: ['router' => $voucher->router?->slug, 'batch_id' => $batch?->id, 'remove_from_router' => $hapusRouter],
        );

        return response()->json(['success' => true, 'message' => 'Voucher dihapus.']);
    }
}
