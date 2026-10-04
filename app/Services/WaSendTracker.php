<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class WaSendTracker
{
    private const TTL = 1800;

    public const STAGES = [
        'queued'     => ['label' => 'Masuk antrean',        'percent' => 15],
        'processing' => ['label' => 'Diproses worker',      'percent' => 45],
        'sending'    => ['label' => 'Dikirim ke WhatsApp',  'percent' => 75],
        'sent'       => ['label' => 'Terkirim',             'percent' => 100],
        'failed'     => ['label' => 'Gagal',                'percent' => 100],
    ];

    private static function key(string $id): string
    {
        return "wa:send:{$id}";
    }

    public static function start(string $id, string $number, ?string $name): void
    {
        Cache::put(self::key($id), [
            'status'     => 'queued',
            'number'     => $number,
            'name'       => $name,
            'attempt'    => 0,
            'error'      => null,
            'message_id' => null,
            'started_at' => now()->toIso8601String(),
        ], self::TTL);
    }

    public static function mark(string $id, string $status, array $extra = []): void
    {
        $cur = Cache::get(self::key($id));
        if (! $cur) {
            return;
        }

        Cache::put(self::key($id), array_merge($cur, $extra, [
            'status'     => $status,
            'updated_at' => now()->toIso8601String(),
        ]), self::TTL);
    }

    public static function get(string $id): ?array
    {
        $d = Cache::get(self::key($id));
        if (! $d) {
            return null;
        }

        $stage = self::STAGES[$d['status']] ?? ['label' => $d['status'], 'percent' => 0];

        return $d + [
            'label'   => $stage['label'],
            'percent' => $stage['percent'],
            'done'    => in_array($d['status'], ['sent', 'failed'], true),
        ];
    }
}
