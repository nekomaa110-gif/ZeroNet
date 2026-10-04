<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\ActivityLogService;
use App\Services\BillingNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvoiceController extends Controller
{
    public function __construct(private BillingNotificationService $notify) {}

    public function index()
    {
        $username = Auth::guard('customer')->user()->getAuthIdentifier();

        return view('customer.invoice.index', [
            'invoices' => Invoice::where('username', $username)
                ->orderByDesc('id')
                ->paginate(20),
            'packageNames' => Package::customerNameMap(),
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwnership($invoice);

        return view('customer.invoice.show', [
            'invoice' => $invoice,
            'banks' => $this->banks(),
        ]);
    }

    public function uploadProof(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeOwnership($invoice);

        if (!in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING], true)) {
            return back()->with('error', 'Invoice tidak dalam status yang dapat di-upload bukti.');
        }

        $data = $request->validate([
            'bank_to' => ['required', 'string', 'max:50'],
            'payment_proof' => ['required', 'image', 'max:8192'],
        ], [
            'payment_proof.required' => 'Foto bukti transfer wajib diunggah.',
            'payment_proof.image'    => 'File harus berupa gambar (JPG/PNG).',
            'payment_proof.max'      => 'Ukuran foto maksimal 8 MB.',
            'bank_to.required'       => 'Pilih rekening tujuan transfer.',
        ]);

        $banks = collect($this->banks())->pluck('key')->all();
        if (!in_array($data['bank_to'], $banks, true)) {
            return back()->withErrors(['bank_to' => 'Rekening tujuan tidak valid.']);
        }

        $path = $request->file('payment_proof')->store('payment_proofs', 'public');

        if ($invoice->payment_proof && Storage::disk('public')->exists($invoice->payment_proof)) {
            Storage::disk('public')->delete($invoice->payment_proof);
        }

        $invoice->update([
            'status' => Invoice::STATUS_PENDING,
            'paid_at' => now(),
            'bank_to' => $data['bank_to'],
            'payment_proof' => $path,
        ]);

        ActivityLogService::log(
            action: 'customer_proof_upload',
            description: "Pelanggan upload bukti transfer: {$invoice->username} (#{$invoice->slug})",
            subjectType: 'customer',
            subjectId: $invoice->username,
            properties: ['invoice_id' => $invoice->id, 'invoice_slug' => $invoice->slug, 'bank_to' => $data['bank_to'], 'ip' => $request->ip()],
        );

        $this->notify->onProofUploaded($invoice->fresh());

        return redirect()->route('customer.invoice.show', $invoice)
            ->with('success', 'Bukti pembayaran terkirim. Mohon tunggu konfirmasi admin.');
    }

    private function authorizeOwnership(Invoice $invoice): void
    {
        $username = Auth::guard('customer')->user()?->getAuthIdentifier();
        if ($invoice->username !== $username) {
            throw new NotFoundHttpException();
        }
    }

    private function banks(): array
    {
        return (array) config('services.billing.rekening', []);
    }
}
