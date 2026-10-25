<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public static function normalizePhone(string $raw): string
    {
        $n = preg_replace('/[^0-9]/', '', $raw);
        if (str_starts_with($n, '0'))   $n = '62' . substr($n, 1);
        if (str_starts_with($n, '620')) $n = '62' . substr($n, 3);
        return $n;
    }

    public function send(string $number, string $message): array
    {
        $cfg = config('services.whatsapp');
        try {
            $res = Http::withHeaders(['x-api-key' => $cfg['key']])
                ->timeout(15)
                ->post(rtrim($cfg['url'], '/').'/send', [
                    'number'  => $number,
                    'message' => $message,
                ]);
            $body = $res->json();
            $ok   = $res->successful() && (($body['ok'] ?? false) === true);
            Log::info('wa.send', [
                'to' => $number, 'ok' => $ok, 'http' => $res->status(), 'body' => $body,
            ]);
            return ['ok' => $ok, 'http' => $res->status(), 'body' => $body];
        } catch (\Throwable $e) {
            Log::error('wa.send.exception', ['err' => $e->getMessage(), 'to' => $number]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendImageUrl(string $number, string $url, ?string $caption = null, array $viewport = []): array
    {
        $cfg = config('services.whatsapp');
        try {
            $res = Http::withHeaders(['x-api-key' => $cfg['key']])
                ->timeout(45)
                ->post(rtrim($cfg['url'], '/').'/send-image-url', array_filter([
                    'number'   => $number,
                    'url'      => $url,
                    'caption'  => $caption,
                    'viewport' => $viewport ?: null,
                ]));
            $body = $res->json();
            $ok   = $res->successful() && (($body['ok'] ?? false) === true);
            Log::info('wa.send_image_url', [
                'to' => $number, 'url' => $url, 'ok' => $ok, 'http' => $res->status(),
                'png_bytes' => $body['png_bytes'] ?? null,
            ]);
            return ['ok' => $ok, 'http' => $res->status(), 'body' => $body];
        } catch (\Throwable $e) {
            Log::error('wa.send_image_url.exception', ['err' => $e->getMessage(), 'to' => $number, 'url' => $url]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendImageHtml(string $number, string $html, ?string $caption = null, array $viewport = []): array
    {
        $cfg = config('services.whatsapp');
        try {
            $res = Http::withHeaders(['x-api-key' => $cfg['key']])
                ->timeout(60)
                ->post(rtrim($cfg['url'], '/').'/send-image-html', array_filter([
                    'number'   => $number,
                    'html'     => $html,
                    'caption'  => $caption,
                    'viewport' => $viewport ?: null,
                ]));
            $body = $res->json();
            $body = is_array($body) ? $body : null;
            $ok   = $res->successful() && (($body['ok'] ?? false) === true);
            Log::info('wa.send_image_html', [
                'to' => $number, 'ok' => $ok, 'http' => $res->status(),
                'png_bytes' => $body['png_bytes'] ?? null, 'code' => $body['code'] ?? null,
            ]);
            return ['ok' => $ok, 'http' => $res->status(), 'body' => $body, 'transport' => null];
        } catch (\Throwable $e) {
            $transport = self::jenisGalatTransport($e->getMessage());
            Log::error('wa.send_image_html.exception', [
                'to' => $number, 'transport' => $transport, 'err' => mb_substr($e->getMessage(), 0, 200),
            ]);
            return ['ok' => false, 'http' => null, 'body' => null, 'transport' => $transport];
        }
    }

    public static function jenisGalatTransport(string $pesan): string
    {
        if (! preg_match('/cURL error (\d+)/', $pesan, $m)) {
            return 'lain';
        }

        return match ((int) $m[1]) {
            7       => 'refused',
            28      => 'timeout',
            52, 56  => 'putus',
            default => 'lain',
        };
    }

    public function status(bool $withQr = false): array
    {
        $cfg = config('services.whatsapp');
        try {
            $res = Http::withHeaders(['x-api-key' => $cfg['key']])
                ->timeout(8)
                ->get(rtrim($cfg['url'], '/').'/status', $withQr ? ['qr' => 1] : []);
            return $res->json() ?: ['ok' => false, 'status' => 'unreachable'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    public function reconnect(): array
    {
        return $this->control('/reconnect');
    }

    public function reset(): array
    {
        return $this->control('/reset');
    }

    private function control(string $path): array
    {
        $cfg = config('services.whatsapp');
        try {
            $res = Http::withHeaders(['x-api-key' => $cfg['key']])
                ->timeout(20)
                ->post(rtrim($cfg['url'], '/').$path);
            $body = $res->json() ?: [];
            Log::info('wa.control', ['path' => $path, 'http' => $res->status(), 'body' => $body]);
            return ['ok' => $res->successful() && ($body['ok'] ?? false)] + $body;
        } catch (\Throwable $e) {
            Log::error('wa.control.exception', ['path' => $path, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
