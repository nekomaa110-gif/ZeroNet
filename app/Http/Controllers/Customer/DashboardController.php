<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Services\ActivityLogService;
use App\Services\BillingNotificationService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    private const RECENT_INVOICE_LIMIT = 3;

    public function __construct(private BillingNotificationService $notify) {}

    public function index(Request $request)
    {
        $user = Auth::guard('customer')->user();
        $username = $user->getAuthIdentifier();

        $expiry = $this->getExpiry($username);

        $invoiceQuery = Invoice::where('username', $username)->orderByDesc('id');
        $invoiceCount = (clone $invoiceQuery)->count();
        $invoices = $invoiceQuery->limit(self::RECENT_INVOICE_LIMIT)->get();

        $openInvoice = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->first();

        $canRequestRenewal = $openInvoice === null && $this->isWithinRenewalWindow($expiry);

        return view('customer.dashboard', [
            'username' => $username,
            'name' => $user->name,
            'phone' => $user->phone,
            'expiry' => $expiry,
            'isExpired' => $expiry ? $expiry->isPast() : null,
            'daysLeft' => $this->daysLeft($expiry),
            'package' => $this->getPackage($username),
            'invoices' => $invoices,
            'invoiceCount' => $invoiceCount,
            'packageNames' => Package::customerNameMap(),
            'openInvoice' => $openInvoice,
            'hasOpenRequest' => $openInvoice !== null,
            'canRequestRenewal' => $canRequestRenewal,
            'renewalOpensAt' => $expiry?->copy()->subDay(),
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }

    public function requestRenewal(Request $request): RedirectResponse
    {
        $user = Auth::guard('customer')->user();
        $username = (string) $user->getAuthIdentifier();

        $lock = Cache::lock('customer.renew.' . sha1($username), 10);

        if (! $lock->get()) {
            return back()->with('info', 'Permintaan perpanjangan sedang diproses. Mohon tunggu sebentar.');
        }

        try {
            return $this->buatPermintaanPerpanjangan($request, $username);
        } finally {
            $lock->release();
        }
    }

    private function buatPermintaanPerpanjangan(Request $request, string $username): RedirectResponse
    {
        $hasOpen = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->exists();

        if ($hasOpen) {
            return back()->with('info', 'Sudah ada permintaan/invoice aktif. Mohon tunggu admin.');
        }

        $expiry = $this->getExpiry($username);
        if (! $this->isWithinRenewalWindow($expiry)) {
            return back()->with('info', 'Akun masih aktif sampai ' . $expiry->translatedFormat('d M Y') . '. Perpanjangan bisa diajukan mulai ' . $expiry->copy()->subDay()->translatedFormat('d M Y') . '.');
        }

        $invoice = Invoice::create([
            'username' => $username,
            'profile' => InvoiceService::DEFAULT_PROFILE,
            'amount' => 0,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => Carbon::now()->addDays(7),
            'notes' => 'Permintaan perpanjangan dari customer portal',
        ]);

        ActivityLogService::log(
            action: 'customer_renew_request',
            description: "Pelanggan minta perpanjangan: {$username}",
            subjectType: 'customer',
            subjectId: $username,
            properties: ['invoice_id' => $invoice->id, 'invoice_slug' => $invoice->slug, 'ip' => $request->ip()],
        );

        $this->notify->onRenewalRequested($username);

        return back()->with('success', 'Permintaan perpanjangan diterima. Admin akan segera membuatkan tagihan.');
    }

    private function isWithinRenewalWindow(?Carbon $expiry): bool
    {
        return $expiry === null
            || $expiry->isPast()
            || $expiry->lte(Carbon::now()->addHours(24));
    }

    private function daysLeft(?Carbon $expiry): ?int
    {
        if ($expiry === null) return null;

        return (int) Carbon::now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);
    }

    private function getPackage(string $username): ?Package
    {
        $groupname = RadUserGroup::where('username', $username)
            ->orderBy('priority')
            ->value('groupname');

        if (!$groupname) return null;

        return Package::where('groupname', $groupname)->first()
            ?? new Package(['groupname' => $groupname]);
    }

    private function getExpiry(string $username): ?Carbon
    {
        $row = RadCheck::where('username', $username)
            ->where('attribute', 'Expiration')
            ->first();

        if (!$row) return null;

        try {
            return Carbon::createFromFormat('d M Y H:i:s', trim($row->value));
        } catch (\Throwable) {
            return null;
        }
    }
}
