<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WaGatewayWatchdog extends Command
{
    public const DOWN_KEY = 'wa.gateway.down';

    public const ALERT_AFTER_SECONDS = 900;

    private const EMAIL_KEY = 'wa.gateway.email_at';

    private const EMAIL_EVERY_SECONDS = 21600;

    protected $signature = 'wa:watchdog';

    protected $description = 'Cek gateway WhatsApp; kalau terputus lama, pasang peringatan di panel dan kirim email ke admin';

    public function handle(WhatsAppService $wa): int
    {
        $status = (string) ($wa->status()['status'] ?? 'unreachable');

        if ($status === 'open') {
            $down = Cache::pull(self::DOWN_KEY);
            Cache::forget(self::EMAIL_KEY);

            if ($down) {
                ActivityLogService::log(
                    action: 'wa_gateway_recovered',
                    description: 'Gateway WhatsApp tersambung lagi setelah terputus sejak ' . date('d M Y H:i', $down['since']),
                    subjectType: 'whatsapp',
                    subjectId: 'gateway',
                );
            }

            $this->line('  [OK]  gateway WA tersambung');

            return self::SUCCESS;
        }

        $down = Cache::get(self::DOWN_KEY) ?: ['since' => time()];
        $down['status'] = $status;
        Cache::forever(self::DOWN_KEY, $down);

        $lama = time() - $down['since'];
        $this->warn(sprintf('  [!]   gateway WA berstatus "%s" sejak %d menit lalu', $status, intdiv($lama, 60)));

        if ($lama < self::ALERT_AFTER_SECONDS || Cache::has(self::EMAIL_KEY)) {
            return self::SUCCESS;
        }

        Cache::put(self::EMAIL_KEY, time(), self::EMAIL_EVERY_SECONDS);

        ActivityLogService::log(
            action: 'wa_gateway_down',
            description: "Gateway WhatsApp berstatus \"{$status}\" sejak " . date('d M Y H:i', $down['since']) . '. Pesan ke pelanggan dan admin tidak terkirim.',
            subjectType: 'whatsapp',
            subjectId: 'gateway',
            properties: ['status' => $status, 'since' => $down['since']],
        );

        $this->kirimEmail($status, $down['since']);

        return self::SUCCESS;
    }

    private function kirimEmail(string $status, int $since): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("  [!]   email tidak dikirim karena MAIL_MAILER={$mailer}, peringatan hanya tampil di panel");

            return;
        }

        $tujuan = User::where('role', 'admin')
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->all();

        if (! $tujuan) {
            $this->warn('  [!]   tidak ada email admin, peringatan hanya tampil di panel');

            return;
        }

        $isi = "Gateway WhatsApp ZeroNet berstatus \"{$status}\" sejak " . date('d M Y H:i', $since) . ".\n\n"
            . "Selama gateway terputus, pengingat, tagihan, konfirmasi pembayaran, dan notifikasi admin tidak terkirim.\n"
            . ($status === 'qr' ? "Gateway minta scan ulang QR.\n" : '')
            . 'Buka ' . rtrim((string) config('app.url'), '/') . "/admin/whatsapp lalu sambungkan ulang gateway.\n";

        try {
            Mail::raw($isi, function ($m) use ($tujuan) {
                $m->to($tujuan)->subject('[ZeroNet] Gateway WhatsApp terputus');
            });
            $this->line('  [OK]  email peringatan dikirim ke ' . implode(', ', $tujuan));
        } catch (\Throwable $e) {
            Log::error('wa.watchdog.mail', ['err' => $e->getMessage()]);
            $this->warn('  [!]   email peringatan gagal: ' . $e->getMessage());
        }
    }
}
