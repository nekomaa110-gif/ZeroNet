<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\AdminNotificationRecipient;
use App\Services\ActivityLogService;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminNotificationController extends Controller
{
    public function index(): View
    {
        return view('admin-notifications.index', [
            'recipients' => AdminNotificationRecipient::orderBy('id')->get(),
            'events'     => AdminNotificationRecipient::EVENTS,
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $recipient = AdminNotificationRecipient::create($data);

        ActivityLogService::log(
            'create',
            "menambah penerima notifikasi WA: {$recipient->name} ({$recipient->phone})",
            'admin_notification',
            (string) $recipient->id,
        );

        return redirect()->route('admin-notifications.index')
            ->with('success', "{$recipient->name} ({$recipient->phone}) akan menerima notifikasi.");
    }

    public function update(Request $request, AdminNotificationRecipient $recipient): RedirectResponse
    {
        $recipient->update($this->validated($request, $recipient));

        ActivityLogService::log(
            'update',
            "mengubah penerima notifikasi WA: {$recipient->name} ({$recipient->phone})",
            'admin_notification',
            (string) $recipient->id,
        );

        return redirect()->route('admin-notifications.index')
            ->with('success', "Pengaturan {$recipient->name} berhasil disimpan.");
    }

    public function toggle(AdminNotificationRecipient $recipient): RedirectResponse
    {
        $recipient->update(['is_active' => ! $recipient->is_active]);
        $state = $recipient->is_active ? 'diaktifkan' : 'dinonaktifkan';

        ActivityLogService::log(
            'update',
            "{$state} penerima notifikasi WA: {$recipient->name}",
            'admin_notification',
            (string) $recipient->id,
        );

        return back()->with('success', "Notifikasi untuk {$recipient->name} {$state}.");
    }

    public function destroy(AdminNotificationRecipient $recipient): RedirectResponse
    {
        $name = $recipient->name;
        $recipient->delete();

        ActivityLogService::log(
            'delete',
            "menghapus penerima notifikasi WA: {$name}",
            'admin_notification',
            (string) $recipient->id,
        );

        $warning = AdminNotificationRecipient::query()->exists()
            ? ''
            : ' Tidak ada penerima tersisa (notifikasi kembali ke nomor bawaan di .env).';

        return redirect()->route('admin-notifications.index')
            ->with('success', "{$name} dihapus dari daftar penerima.{$warning}");
    }

    public function test(AdminNotificationRecipient $recipient): RedirectResponse
    {
        $langganan = $recipient->eventLabels();

        $message = "🔔 *Tes Notifikasi ZeroNet*\n\n"
            . "Halo {$recipient->name}, nomor ini terdaftar menerima notifikasi admin:\n\n"
            . ($langganan
                ? '• ' . implode("\n• ", $langganan)
                : '_(belum ada jenis notifikasi yang dicentang)_')
            . "\n\nKalau pesan ini sampai, pengaturannya sudah benar.";

        SendWhatsAppMessage::dispatch($recipient->phone, $message);

        ActivityLogService::log(
            'update',
            "mengirim tes notifikasi WA ke {$recipient->name} ({$recipient->phone})",
            'admin_notification',
            (string) $recipient->id,
        );

        return back()->with('success', "Pesan tes dikirim ke {$recipient->phone}. Cek WhatsApp nomor itu.");
    }

    private function validated(Request $request, ?AdminNotificationRecipient $current = null): array
    {
        $request->merge([
            'phone' => WhatsAppService::normalizePhone((string) $request->input('phone', '')),
        ]);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'phone'     => [
                'required', 'string', 'regex:/^62[0-9]{8,15}$/',
                Rule::unique('admin_notification_recipients', 'phone')->ignore($current?->id),
            ],
            'events'    => ['required', 'array', 'min:1'],
            'events.*'  => [Rule::in(array_keys(AdminNotificationRecipient::EVENTS))],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required'   => 'Nama penerima wajib diisi.',
            'phone.required'  => 'Nomor WhatsApp wajib diisi.',
            'phone.regex'     => 'Nomor tidak valid. Contoh: 081234567890 atau 6281234567890.',
            'phone.unique'    => 'Nomor ini sudah ada di daftar penerima.',
            'events.required' => 'Pilih minimal satu jenis notifikasi.',
            'events.min'      => 'Pilih minimal satu jenis notifikasi.',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }
}
