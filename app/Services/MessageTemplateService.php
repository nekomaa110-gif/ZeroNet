<?php

namespace App\Services;

use App\Models\MessageTemplate;
use Illuminate\Support\Facades\Cache;

class MessageTemplateService
{
    private const CACHE_KEY = 'message_templates.overrides';

    public static function body(string $key): string
    {
        return self::overrides()[$key]
            ?? (string) config("message-templates.items.{$key}.default", '');
    }

    public static function render(string $key, array $vars = []): string
    {
        $vars += self::globalVars();

        $replace = [];
        foreach ($vars as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr(self::body($key), $replace);
    }

    public static function globalVars(): array
    {
        $portal = (string) config('services.billing.portal_url');

        return [
            'portal_login' => preg_replace('#^https?://#', '', $portal) . '/login',
            'admin_wa'     => (string) config('services.billing.business_wa'),
        ];
    }

    public static function all(): array
    {
        $overrides = self::overrides();
        $items     = [];

        foreach ((array) config('message-templates.items', []) as $key => $def) {
            $items[$key] = $def + [
                'key'        => $key,
                'body'       => $overrides[$key] ?? $def['default'],
                'customized' => isset($overrides[$key]),
            ];
        }

        return $items;
    }

    public static function definition(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function sampleVars(string $key): array
    {
        $meta = (array) config('message-templates.placeholder_meta', []);
        $vars = [];

        foreach (self::placeholdersOf($key) as $name) {
            $vars[$name] = $meta[$name][1] ?? "{{$name}}";
        }

        return $vars + self::globalVars();
    }

    public static function placeholdersOf(string $key): array
    {
        $own    = (array) config("message-templates.items.{$key}.placeholders", []);
        $global = array_keys((array) config('message-templates.global_placeholders', []));

        return array_values(array_unique([...$own, ...$global]));
    }

    public static function save(string $key, string $body, ?int $userId = null): void
    {
        MessageTemplate::updateOrCreate(
            ['key' => $key],
            ['body' => $body, 'updated_by' => $userId],
        );

        self::flush();
    }

    public static function reset(string $key): void
    {
        MessageTemplate::where('key', $key)->delete();

        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function overrides(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHours(6),
            fn () => MessageTemplate::pluck('body', 'key')->all(),
        );
    }
}
