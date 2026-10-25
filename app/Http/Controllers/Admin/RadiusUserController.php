<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvoiceTerbuka;
use App\Exceptions\KirimUlangDitolak;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RadiusUserRequest;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\WaVoucherDelivery;
use App\Jobs\SendVoucherWhatsApp;
use App\Services\ActivityLogService;
use App\Services\BillingNotificationService;
use App\Services\CustomerContactService;
use App\Services\CustomerPriceService;
use App\Services\InvoiceService;
use App\Services\RadiusUserService;
use App\Services\VoucherWaService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RadiusUserController extends Controller
{
    public const KEMBALI_KUNCI = ['search', 'group', 'status', 'sort', 'harga', 'page'];

    public function __construct(
        private RadiusUserService $service,
        private InvoiceService $invoices,
        private CustomerContactService $kontak,
        private VoucherWaService $voucherWa,
        private CustomerPriceService $prices,
    ) {}

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $group  = $request->string('group')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $sort   = $request->string('sort')->trim()->toString() === 'down' ? 'down' : 'up';
        $harga  = $request->string('harga')->trim()->toString() === 'kosong' ? 'kosong' : '';
        $users  = $this->service->paginate($search, 15, $group, $status, $sort, $harga);

        if ($request->ajax()) {
            return view('user-hotspot._results', compact('users', 'search', 'group', 'status', 'sort', 'harga'));
        }

        $groups     = $this->service->availableGroups();
        $stats      = $this->service->stats();
        $tanpaHarga = $this->service->jumlahTanpaHarga();

        return view('user-hotspot.index', compact('users', 'search', 'group', 'status', 'sort', 'harga', 'groups', 'stats', 'tanpaHarga'));
    }

    public function create(): View
    {
        $groups     = $this->service->availableGroups();
        $hargaPaket = $this->prices->petaHargaPaket($groups);

        return view('user-hotspot.create', compact('groups', 'hargaPaket'));
    }

    public function store(RadiusUserRequest $request): RedirectResponse
    {
        $data     = $request->validated();
        $username = $data['username'];
        $mode     = ($data['kirim_voucher'] ?? null) === 'otomatis' ? 'otomatis' : 'manual';
        $phone    = CustomerContactService::normalisasi($data['phone'] ?? null);
        $kunci    = Cache::lock('user-hotspot:' . mb_strtolower($username), 10);
        $minta    = $this->hargaDariRequest($request, $username);
        $harga    = $minta['pakai'] || $this->prices->isGratis($username)
            ? $minta['harga']
            : $this->prices->hargaPaket($data['group'] ?? null);

        if (! $kunci->get()) {
            return back()->withInput($request->except('password'))->withErrors(['username' => 'Username sedang diproses, coba lagi sebentar.']);
        }

        try {
            if (RadCheck::where('username', $username)->exists()) {
                return back()->withInput($request->except('password'))->withErrors(['username' => 'Username sudah digunakan.']);
            }

            [$nomor, $kiriman, $hasilHarga] = DB::transaction(function () use ($data, $username, $mode, $phone, $request, $harga) {
                $this->service->create($data);
                $hasilHarga = $this->prices->simpan($username, $harga, $request->user()?->id);
                $nomor   = $phone ? $this->kontak->ubahNomor($username, $phone) : null;
                $kiriman = $mode === 'otomatis' ? $this->voucherWa->buatKiriman($username, $request->user()?->id) : null;

                if ($kiriman?->status === WaVoucherDelivery::ANTRE) {
                    SendVoucherWhatsApp::dispatch($kiriman->id)->afterCommit();
                }

                return [$nomor, $kiriman, $hasilHarga];
            }, 3);
        } finally {
            $kunci->release();
        }

        $this->prices->catatPerubahan($username, $hasilHarga);

        if ($nomor) {
            $this->kontak->catatPerubahan($username, $nomor, 'tambah_user');
        }

        ActivityLogService::log(
            'create',
            "membuat user hotspot: {$username}",
            'radius_user',
            $username,
            ['group' => $data['group'] ?? null, 'kirim_voucher' => $mode]
        );

        $redirect = redirect()->route('user-hotspot.index');

        if ($mode === 'manual') {
            return $redirect->with('voucher_baru', $username);
        }

        return $redirect->with('success', "User {$username} berhasil dibuat." . ($kiriman->status === WaVoucherDelivery::ANTRE
            ? " Voucher sedang dikirim ke {$kiriman->phone}; statusnya ada di detail user."
            : " Voucher tidak dikirim: {$kiriman->reason}."));
    }

    public function edit(Request $request, string $username): View
    {
        $user   = $this->service->find($username);
        $gratis = $this->prices->isGratis($username);
        $harga  = $gratis ? null : $this->prices->harga($username);
        $saran  = $gratis || $harga !== null ? null : $this->prices->saran($username);
        $kembali = self::kembaliDari($request->query());
        $groups = $this->service->availableGroups();
        $openInvoices = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->get(['id', 'amount', 'status']);
        $phone = CustomerContact::where('username', $username)->value('phone');

        return view('user-hotspot.edit', compact('user', 'groups', 'openInvoices', 'phone', 'gratis', 'harga', 'saran', 'kembali'));
    }

    public function voucher(string $username): View
    {
        $user = $this->service->find($username);

        ActivityLogService::log(
            'print_voucher',
            "mencetak voucher akun: {$username}",
            'radius_user',
            $username
        );

        return view('user-hotspot.voucher', compact('user'));
    }

    public function kirimUlang(Request $request, string $username): RedirectResponse
    {
        abort_unless(
            RadCheck::where('username', $username)->where('attribute', 'Cleartext-Password')->exists(),
            404
        );

        try {
            $kiriman = DB::transaction(function () use ($username, $request) {
                $kiriman = $this->voucherWa->kirimUlang($username, $request->user()?->id);

                if ($kiriman->status === WaVoucherDelivery::ANTRE) {
                    SendVoucherWhatsApp::dispatch($kiriman->id)->afterCommit();
                }

                return $kiriman;
            });
        } catch (KirimUlangDitolak $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLogService::log(
            'voucher_wa_kirim',
            "Kirim ulang voucher WA: {$username}",
            'radius_user',
            $username,
            ['status' => $kiriman->status, 'reason' => $kiriman->reason, 'phone' => $kiriman->phone]
        );

        return $kiriman->status === WaVoucherDelivery::ANTRE
            ? back()->with('success', "Voucher {$username} dikirim ulang ke {$kiriman->phone}.")
            : back()->with('error', "Voucher tidak dikirim: {$kiriman->reason}.");
    }

    public function update(RadiusUserRequest $request, string $username): RedirectResponse
    {
        $data  = $request->validated();
        $aksi  = $data['invoice_aksi'] ?? null;
        $admin = $request->user();
        $minta = $this->hargaDariRequest($request, $username);
        $terlewat = $minta['pakai'] && $minta['harga'] !== null ? $this->prices->siklusTerlewat($username) : null;

        try {
            [$invoice, $sampai, $nomor, $hasilHarga] = DB::transaction(function () use ($username, $data, $aksi, $admin, $minta) {
                $lama = RadCheck::where('username', $username)
                    ->where('attribute', 'Expiration')
                    ->lockForUpdate()
                    ->value('value');
                $lama = $lama ? Carbon::parse($lama) : null;
                $baru = ! empty($data['expiry']) ? Carbon::parse($data['expiry'])->setTime(23, 59, 59) : null;

                $invoice = null;

                if ($lama !== null && ($baru === null || $baru->toDateString() > $lama->toDateString())) {
                    $terbuka = Invoice::where('username', $username)
                        ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
                        ->lockForUpdate()
                        ->get();

                    $invoice = $this->invoiceUntukDitutup($terbuka, $aksi, $data['invoice_id'] ?? null, $admin->isAdmin());
                }

                $this->service->update($username, $data);

                $hasilHarga = $minta['pakai'] ? $this->prices->simpan($username, $minta['harga'], (int) $admin->id) : null;

                $phone = CustomerContactService::normalisasi($data['phone'] ?? null);
                $nomor = $phone ? $this->kontak->ubahNomor($username, $phone) : null;

                if ($invoice) {
                    $invoice = $this->invoices->tutupLewatPerpanjang($invoice, $aksi, (int) $admin->id, $baru, 'Edit user');
                }

                return [$invoice, $baru, $nomor, $hasilHarga];
            }, 3);
        } catch (InvoiceTerbuka $e) {
            return back()->withInput()->withErrors(['invoice_aksi' => $e->getMessage()]);
        }

        $catatanInvoice = $invoice
            ? ($aksi === 'lunas' ? ", invoice #{$invoice->id} ditandai lunas" : ", invoice #{$invoice->id} dibatalkan")
            : '';

        ActivityLogService::log(
            'update',
            "mengupdate user hotspot: {$username}{$catatanInvoice}",
            'radius_user',
            $username,
            $invoice ? ['invoice_id' => $invoice->id, 'invoice_aksi' => $aksi] : []
        );

        if ($invoice) {
            $this->catatInvoiceDitutup($invoice, $aksi, $username, $sampai, 'Edit user');
        }

        if ($nomor) {
            $this->kontak->catatPerubahan($username, $nomor, 'edit_user');
        }

        if ($hasilHarga) {
            $this->prices->catatPerubahan($username, $hasilHarga);
        }

        $invoiceNote = $invoice
            ? ($aksi === 'lunas' ? " Invoice #{$invoice->id} ditandai lunas." : " Invoice #{$invoice->id} dibatalkan.")
            : '';
        $nomorNote = $nomor ? $this->kontak->pesanSukses($username, $nomor) : '';
        $hargaNote = $hasilHarga ? $this->prices->pesanSukses($hasilHarga) : '';

        $kembali = self::kembaliDari((array) $request->input('kembali', []));

        if (($kembali['harga'] ?? '') === 'kosong') {
            unset($kembali['page']);
        }

        $redirect = redirect()
            ->route('user-hotspot.index', $kembali)
            ->with('success', "User {$username} berhasil diperbarui.{$invoiceNote}{$nomorNote}{$hargaNote}");

        $diulangReminder = ($nomor['berubah'] ?? false)
            && $terlewat !== null
            && $sampai !== null
            && $sampai->isSameDay($terlewat)
            && $sampai->gt(now()->addHours((int) config('services.whatsapp.reminder_hours', 24)));

        if ($terlewat && ($hasilHarga['berubah'] ?? false) && ! $diulangReminder) {
            $redirect->with('harga_terlewat', ['username' => $username, 'expire' => $terlewat->format('d M Y')]);
        }

        return $redirect;
    }

    private function catatInvoiceDitutup(Invoice $invoice, string $aksi, string $username, ?Carbon $sampai, string $jalur): void
    {
        ActivityLogService::log(
            'update',
            $aksi === 'lunas'
                ? "invoice #{$invoice->id} ({$username}) ditandai lunas lewat {$jalur}"
                : "membatalkan invoice #{$invoice->id} ({$username}) (diperpanjang lewat {$jalur})",
            'invoice',
            (string) $invoice->id,
            ['extended_to' => $sampai?->format('Y-m-d H:i:s')]
        );
    }

    public function extend(Request $request, string $username): RedirectResponse
    {
        $validated = $request->validate([
            'days'         => ['nullable', 'integer', 'min:1', 'max:365'],
            'invoice_aksi' => ['nullable', Rule::in(['lunas', 'batal'])],
            'invoice_id'   => ['nullable', 'integer'],
        ]);

        $days  = (int) ($validated['days'] ?? 30);
        $aksi  = $validated['invoice_aksi'] ?? null;
        $admin = $request->user();

        try {
            [$result, $invoice] = DB::transaction(function () use ($username, $days, $aksi, $validated, $admin) {
                $terbuka = Invoice::where('username', $username)
                    ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
                    ->lockForUpdate()
                    ->get();

                $invoice = $this->invoiceUntukDitutup($terbuka, $aksi, $validated['invoice_id'] ?? null, $admin->isAdmin());
                $result  = $this->service->extend($username, $days);

                if ($invoice) {
                    $invoice = $this->invoices->tutupLewatPerpanjang($invoice, $aksi, (int) $admin->id, $result['to']);
                }

                return [$result, $invoice];
            });
        } catch (InvoiceTerbuka $e) {
            return back()->with('error', $e->getMessage());
        }

        $until = $result['to']->translatedFormat('d M Y');
        $catatanInvoice = $invoice
            ? ($aksi === 'lunas' ? ", invoice #{$invoice->id} ditandai lunas" : ", invoice #{$invoice->id} dibatalkan")
            : '';

        ActivityLogService::log(
            'extend',
            "memperpanjang user hotspot {$username} +{$days} hari (sampai {$until}){$catatanInvoice}",
            'radius_user',
            $username,
            [
                'days' => $days,
                'from' => $result['from']?->format('Y-m-d H:i:s'),
                'to'   => $result['to']->format('Y-m-d H:i:s'),
            ] + ($invoice ? ['invoice_id' => $invoice->id, 'invoice_aksi' => $aksi] : [])
        );

        if ($invoice) {
            $this->catatInvoiceDitutup($invoice, $aksi, $username, $result['to'], 'Perpanjang manual');
        }

        $notified = app(BillingNotificationService::class)->onAccountExtended(
            $username,
            $days,
            $result['to'],
            $result['from'],
            $this->service->find($username)['group'] ?? null,
        );

        $note = $result['from_expired']
            ? ' (akun sudah lewat expire, dihitung dari hari ini)'
            : '';

        $waNote = $notified
            ? ' Notifikasi WA diantre.'
            : ' Notifikasi WA tidak dikirim (nomor pelanggan belum terdaftar atau notifikasi WA dimatikan).';

        $invoiceNote = $invoice
            ? ($aksi === 'lunas' ? " Invoice #{$invoice->id} ditandai lunas." : " Invoice #{$invoice->id} dibatalkan.")
            : '';

        return back()->with('success', "User {$username} diperpanjang {$days} hari, aktif sampai {$until}.{$note}{$invoiceNote}{$waNote}");
    }

    public static function kembaliDari(array $query): array
    {
        return array_filter(
            Arr::only($query, self::KEMBALI_KUNCI),
            fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 100
        );
    }

    private function hargaDariRequest(RadiusUserRequest $request, string $username): array
    {
        if ($this->prices->isGratis($username)) {
            return ['pakai' => false, 'harga' => null];
        }

        if (! $request->user()?->isAdmin() || ! $request->exists('price')) {
            return ['pakai' => false, 'harga' => null];
        }

        $harga = CustomerPriceService::normalisasi($request->input('price'));

        return ['pakai' => true, 'harga' => $harga === false ? null : $harga];
    }

    private function invoiceUntukDitutup(Collection $terbuka, ?string $aksi, ?int $invoiceId, bool $admin): ?Invoice
    {
        if ($terbuka->isEmpty()) {
            if ($aksi !== null) {
                throw new InvoiceTerbuka('Invoice yang dipilih sudah tidak terbuka (mungkin baru dikonfirmasi atau dibatalkan). Muat ulang halaman sebelum memperpanjang.');
            }

            return null;
        }

        $daftar = $terbuka->map(fn (Invoice $i) => "#{$i->id}")->implode(', ');

        if ($terbuka->count() > 1) {
            throw new InvoiceTerbuka("User ini punya {$terbuka->count()} invoice terbuka ({$daftar}). Rapikan dulu di halaman Tagihan, baru perpanjang.");
        }

        if (! $admin) {
            throw new InvoiceTerbuka("User ini masih punya invoice {$daftar} yang belum selesai. Minta admin memperpanjang supaya invoice itu ikut ditutup.");
        }

        if ($aksi === null) {
            throw new InvoiceTerbuka("User ini masih punya invoice {$daftar}. Pilih dulu: tandai lunas atau batalkan.");
        }

        $invoice = $terbuka->first();

        if ($invoiceId !== $invoice->id) {
            throw new InvoiceTerbuka("Invoice terbuka user ini sekarang {$daftar}, berbeda dengan yang tampil di halaman. Muat ulang halaman lalu ulangi.");
        }

        return $invoice;
    }

    public function destroy(Request $request, string $username): RedirectResponse
    {
        $pwd = (string) $request->input('current_password', '');

        if ($pwd === '' || ! Hash::check($pwd, Auth::user()->password)) {
            ActivityLogService::log(
                'password_confirm_failed',
                "Konfirmasi password gagal saat hapus user hotspot: {$username}",
                'radius_user',
                $username,
            );

            return back()->with('forbidden', "Password salah. User {$username} TIDAK dihapus.");
        }

        $this->service->delete($username);

        ActivityLogService::log(
            'delete',
            "menghapus user hotspot: {$username}",
            'radius_user',
            $username
        );

        return back()->with('success', "User {$username} berhasil dihapus.");
    }

    public function toggle(string $username): RedirectResponse
    {
        $nowActive = $this->service->toggle($username);
        $status    = $nowActive ? 'diaktifkan' : 'dinonaktifkan';

        ActivityLogService::log(
            'update',
            "{$status} user hotspot: {$username}",
            'radius_user',
            $username,
            ['active' => $nowActive]
        );

        return back()->with('success', "User {$username} berhasil {$status}.");
    }
}
