<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvoiceTerbuka;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RadiusUserRequest;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Services\ActivityLogService;
use App\Services\BillingNotificationService;
use App\Services\InvoiceService;
use App\Services\RadiusUserService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RadiusUserController extends Controller
{
    public function __construct(
        private RadiusUserService $service,
        private InvoiceService $invoices,
    ) {}

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $group  = $request->string('group')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $sort   = $request->string('sort')->trim()->toString() === 'down' ? 'down' : 'up';
        $users  = $this->service->paginate($search, 15, $group, $status, $sort);

        if ($request->ajax()) {
            return view('user-hotspot._results', compact('users', 'search', 'group', 'status', 'sort'));
        }

        $groups = $this->service->availableGroups();
        $stats  = $this->service->stats();

        return view('user-hotspot.index', compact('users', 'search', 'group', 'status', 'sort', 'groups', 'stats'));
    }

    public function create(): View
    {
        $groups = $this->service->availableGroups();
        return view('user-hotspot.create', compact('groups'));
    }

    public function store(RadiusUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->service->create($data);

        ActivityLogService::log(
            'create',
            "membuat user hotspot: {$data['username']}",
            'radius_user',
            $data['username'],
            ['group' => $data['group'] ?? null]
        );

        return redirect()
            ->route('user-hotspot.index')
            ->with('success', "User {$data['username']} berhasil dibuat.");
    }

    public function edit(string $username): View
    {
        $user   = $this->service->find($username);
        $groups = $this->service->availableGroups();
        $openInvoices = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->get(['id', 'amount', 'status']);

        return view('user-hotspot.edit', compact('user', 'groups', 'openInvoices'));
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

    public function update(RadiusUserRequest $request, string $username): RedirectResponse
    {
        $data  = $request->validated();
        $aksi  = $data['invoice_aksi'] ?? null;
        $admin = $request->user();

        try {
            [$invoice, $sampai] = DB::transaction(function () use ($username, $data, $aksi, $admin) {
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

                if ($invoice) {
                    $invoice = $this->invoices->tutupLewatPerpanjang($invoice, $aksi, (int) $admin->id, $baru, 'Edit user');
                }

                return [$invoice, $baru];
            });
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

        $invoiceNote = $invoice
            ? ($aksi === 'lunas' ? " Invoice #{$invoice->id} ditandai lunas." : " Invoice #{$invoice->id} dibatalkan.")
            : '';

        return redirect()
            ->route('user-hotspot.index')
            ->with('success', "User {$username} berhasil diperbarui.{$invoiceNote}");
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
