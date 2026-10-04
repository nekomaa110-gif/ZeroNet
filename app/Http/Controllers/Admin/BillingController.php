<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Services\ActivityLogService;
use App\Services\BillingNotificationService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private InvoiceService $service,
        private BillingNotificationService $notify,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->trim()->toString();
        $search = $request->string('search')->trim()->toString();

        $query = Invoice::query()->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where('username', 'like', "%{$search}%");
        }

        $invoices = $query->paginate(20)->withQueryString();

        $counts = Invoice::selectRaw('status, COUNT(*) c')
            ->groupBy('status')->pluck('c', 'status')->all();

        if ($request->ajax()) {
            return view('admin.billing._results', compact('invoices'));
        }

        return view('admin.billing.index', [
            'invoices' => $invoices,
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function create(Request $request): View
    {
        $username = $request->string('username')->toString();
        $hint = null;

        if ($username) {
            $hint = $this->buildUserHint($username);
        }

        return view('admin.billing.create', [
            'username' => $username,
            'hint' => $hint,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:1000', 'max:99999999'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'profile' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $exists = RadCheck::where('username', $data['username'])
            ->where('attribute', 'Cleartext-Password')->exists();

        if (! $exists) {
            return back()->withInput()->withErrors([
                'username' => "User \"{$data['username']}\" tidak ditemukan di radcheck.",
            ]);
        }

        $terbuka = Invoice::where('username', $data['username'])
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->first();

        if ($terbuka) {
            return back()->withInput()->withErrors([
                'username' => "User \"{$data['username']}\" masih punya invoice #{$terbuka->id} yang belum selesai. Selesaikan atau batalkan invoice itu dulu.",
            ]);
        }

        $invoice = $this->service->createForUser(
            username: $data['username'],
            amount: (int) $data['amount'],
            dueDate: Carbon::parse($data['due_date'])->endOfDay(),
            profile: $data['profile'] ?: InvoiceService::DEFAULT_PROFILE,
            notes: $data['notes'] ?? null,
        );

        ActivityLogService::log(
            'create',
            "membuat invoice #{$invoice->id} untuk {$invoice->username} (Rp " . number_format($invoice->amount, 0, ',', '.') . ")",
            'invoice',
            (string) $invoice->id,
        );

        $this->notify->onInvoicePublished($invoice);

        return redirect()->route('billing.show', $invoice)
            ->with('success', "Invoice #{$invoice->id} dibuat.");
    }

    public function show(Invoice $invoice): View
    {
        $contact = CustomerContact::where('username', $invoice->username)->first();
        $expiry = $this->getExpiry($invoice->username);

        return view('admin.billing.show', [
            'invoice' => $invoice,
            'contact' => $contact,
            'expiry' => $expiry,
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }

    public function publish(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Invoice bukan dalam status draft.');
        }

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:99999999'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->publish(
            invoice: $invoice,
            amount: (int) $data['amount'],
            dueDate: Carbon::parse($data['due_date'])->endOfDay(),
            notes: $data['notes'] ?? null,
        );

        ActivityLogService::log(
            'update',
            "publish invoice #{$invoice->id} untuk {$invoice->username} (Rp " . number_format($data['amount'], 0, ',', '.') . ")",
            'invoice',
            (string) $invoice->id,
        );

        $this->notify->onInvoicePublished($invoice->fresh());

        return back()->with('success', "Invoice #{$invoice->id} dipublikasikan.");
    }

    public function confirm(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_UNPAID], true)) {
            return back()->with('error', 'Invoice tidak dalam status yang dapat dikonfirmasi.');
        }

        $invoice = $this->service->confirmPayment(
            invoice: $invoice,
            adminId: (int) $request->user()->id,
        );

        if (! $invoice) {
            return back()->with('error', 'Invoice sudah dikonfirmasi atau statusnya berubah. Muat ulang halaman.');
        }

        ActivityLogService::log(
            'update',
            "konfirmasi pembayaran invoice #{$invoice->id} ({$invoice->username})",
            'invoice',
            (string) $invoice->id,
            ['extended_to' => $invoice->extended_to?->toDateTimeString()],
        );

        $this->notify->onPaymentConfirmed($invoice);

        return back()->with('success', "Invoice #{$invoice->id} dikonfirmasi lunas. Akun aktif sampai {$invoice->extended_to->translatedFormat('d M Y')}.");
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING], true)) {
            return back()->with('error', 'Invoice tidak bisa dibatalkan.');
        }

        $reason = $request->string('reason')->trim()->toString();
        $this->service->cancel($invoice, $reason ?: null);

        ActivityLogService::log(
            'update',
            "membatalkan invoice #{$invoice->id} ({$invoice->username})" . ($reason ? " ({$reason})" : ''),
            'invoice',
            (string) $invoice->id,
        );

        return back()->with('success', "Invoice #{$invoice->id} dibatalkan.");
    }

    private function buildUserHint(string $username): array
    {
        $exists = RadCheck::where('username', $username)
            ->where('attribute', 'Cleartext-Password')->exists();
        $contact = CustomerContact::where('username', $username)->first();
        $expiry = $this->getExpiry($username);

        return [
            'exists' => $exists,
            'contact' => $contact,
            'expiry' => $expiry,
        ];
    }

    private function getExpiry(string $username): ?Carbon
    {
        $row = RadCheck::where('username', $username)
            ->where('attribute', 'Expiration')->first();

        if (! $row) return null;
        try {
            return Carbon::createFromFormat('d M Y H:i:s', trim($row->value));
        } catch (\Throwable) {
            return null;
        }
    }
}
