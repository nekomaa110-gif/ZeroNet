<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'url'             => env('WA_GATEWAY_URL', 'http://127.0.0.1:3001'),
        'key'             => env('WA_GATEWAY_KEY'),
        'reminder_hours'  => (int) env('WA_REMINDER_HOURS_BEFORE', 24),
    ],

    'whatsapp_cloud' => [
        'app_id'        => (string) env('META_APP_ID', ''),
        'app_secret'    => (string) env('META_APP_SECRET', ''),
        'config_id'     => (string) env('META_ES_CONFIG_ID', ''),
        'graph_version' => (string) env('META_GRAPH_VERSION', 'v23.0'),
        'phone_id'      => (string) env('WA_CLOUD_PHONE_ID', ''),
        'waba_id'       => (string) env('WA_CLOUD_WABA_ID', ''),
        'token'         => (string) env('WA_CLOUD_TOKEN', ''),
    ],

    'mikrotik' => [
        'backup_password' => (string) env('ROUTER_BACKUP_PASSWORD', ''),
        'sftp'            => [
            'host'             => (string) env('ROUTER_BACKUP_SFTP_HOST', ''),
            'port'             => (int) env('ROUTER_BACKUP_SFTP_PORT', 22),
            'user'             => (string) env('ROUTER_BACKUP_SFTP_USER', ''),
            'password'         => (string) env('ROUTER_BACKUP_SFTP_PASSWORD', ''),
            'direktori_remote' => (string) env('ROUTER_BACKUP_SFTP_REMOTE_DIR', 'incoming'),
            'direktori_lokal'  => (string) env('ROUTER_BACKUP_SFTP_LOCAL_DIR', '/srv/router-backup/incoming'),
        ],
        'akun_panel'      => [
            'nama'   => 'zeronet-api',
            'grup'   => 'zeronet-api',
            'policy' => ['api', 'read', 'write', 'policy', 'test', 'reboot', 'sensitive'],
        ],
        'timeout'         => ['web' => 8, 'antrean' => 25, 'poller' => 4],
        'down_ttl'        => 15,
        'idle_ulang'      => 20,
        'kunci_tulis_ttl' => 660,
    ],

    'billing' => [
        'wa_notifications'  => (bool) env('BILLING_WA_NOTIFICATIONS', false),
        'wa_test_mode'      => (bool) env('BILLING_WA_TEST_MODE', true),
        'wa_test_number'    => (string) env('BILLING_WA_TEST_NUMBER', ''),

        'business_wa'       => (string) env('BUSINESS_WA', ''),

        'admin_notif_wa'    => (string) env('ADMIN_NOTIF_WA', env('BUSINESS_WA', '')),

        'portal_url'        => 'https://' . (string) env('APP_DOMAIN', 'localhost'),

        'rekening'          => collect(explode(';', (string) env('BILLING_REKENING', '')))
            ->map(fn (string $baris) => array_map('trim', explode('|', $baris)))
            ->filter(fn (array $f) => count($f) === 3 && $f[0] !== '' && $f[1] !== '' && $f[2] !== '')
            ->map(fn (array $f) => [
                'key'     => \Illuminate\Support\Str::slug(preg_replace('/^bank\s+/i', '', $f[0]) . '-' . $f[2]),
                'bank'    => $f[0],
                'account' => $f[1],
                'holder'  => $f[2],
            ])
            ->values()
            ->all(),
    ],

];
