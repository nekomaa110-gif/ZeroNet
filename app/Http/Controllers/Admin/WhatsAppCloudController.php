<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppCloudController extends Controller
{
    private const TIMEOUT = 20;

    public function index()
    {
        $c = config('services.whatsapp_cloud');

        return view('whatsapp.cloud', [
            'appId'     => $c['app_id'],
            'configId'  => $c['config_id'],
            'graphVer'  => $c['graph_version'],
            'phoneId'   => $c['phone_id'],
            'wabaId'    => $c['waba_id'],

            'hasSecret' => $c['app_secret'] !== '',
            'hasToken'  => $c['token'] !== '',
        ]);
    }

    public function exchange(Request $r)
    {
        $data = $r->validate([
            'code'     => ['required', 'string', 'max:4000'],
            'waba_id'  => ['nullable', 'string', 'max:64'],
            'phone_id' => ['nullable', 'string', 'max:64'],
        ]);

        $c = config('services.whatsapp_cloud');

        if ($c['app_id'] === '' || $c['app_secret'] === '') {
            return response()->json([
                'ok'    => false,
                'error' => 'META_APP_ID / META_APP_SECRET belum diisi di .env.',
            ], 422);
        }

        $res = Http::timeout(self::TIMEOUT)
            ->get("https://graph.facebook.com/{$c['graph_version']}/oauth/access_token", [
                'client_id'     => $c['app_id'],
                'client_secret' => $c['app_secret'],
                'redirect_uri'  => route('whatsapp.cloud.index'),
                'code'          => $data['code'],
            ]);

        $token = $res->json('access_token');

        if (! $res->successful() || ! $token) {
            return response()->json([
                'ok'    => false,
                'error' => $res->json('error.message') ?: 'Gagal menukar code jadi access token.',
            ], 422);
        }

        ActivityLogService::log(
            action: 'wa_cloud_onboarded',
            description: 'Nomor bisnis tersambung ke WhatsApp Cloud API (Coexistence)',
            subjectType: 'whatsapp',
            subjectId: 'cloud',
            properties: ['waba_id' => $data['waba_id'] ?? null, 'phone_id' => $data['phone_id'] ?? null],
        );

        $wabaId  = ($data['waba_id'] ?? null) ?: $this->discoverWabaId($token, $c);
        $phoneId = ($data['phone_id'] ?? null) ?: $this->discoverPhoneId($token, $c, $wabaId);

        return response()->json([
            'ok'       => true,
            'token'    => $token,
            'waba_id'  => $wabaId,
            'phone_id' => $phoneId,
        ]);
    }

    public function redirectStart()
    {
        $c = config('services.whatsapp_cloud');

        if ($c['app_id'] === '' || $c['config_id'] === '') {
            return redirect()->route('whatsapp.cloud.index')
                ->with('wc_error', 'META_APP_ID / META_ES_CONFIG_ID belum diisi.');
        }

        $state = Str::random(40);
        session()->put('wa_cloud_oauth_state', $state);

        $params = http_build_query([
            'client_id'                      => $c['app_id'],
            'config_id'                      => $c['config_id'],
            'state'                          => $state,
            'response_type'                  => 'code',
            'override_default_response_type' => 'true',
            'redirect_uri'                   => route('whatsapp.cloud.callback'),
            'extras'                         => json_encode([
                'setup'              => (object) [],
                'featureType'        => 'whatsapp_business_app_onboarding',
                'sessionInfoVersion' => '3',
            ]),
        ]);

        return redirect()->away("https://www.facebook.com/{$c['graph_version']}/dialog/oauth?{$params}");
    }

    public function callback(Request $r)
    {
        $state = (string) $r->session()->pull('wa_cloud_oauth_state', '');

        if ($state === '' || ! hash_equals($state, (string) $r->query('state', ''))) {
            return redirect()->route('whatsapp.cloud.index')
                ->with('wc_error', 'Sesi penyambungan tidak cocok atau kedaluwarsa. Ulangi dari tombol Sambungkan.');
        }

        if ($r->query('error') || ! $r->query('code')) {
            return redirect()->route('whatsapp.cloud.index')->with(
                'wc_error',
                $r->query('error_message') ?: 'Penyambungan dibatalkan atau ditolak Meta.'
            );
        }

        $c = config('services.whatsapp_cloud');

        $res = Http::timeout(self::TIMEOUT)
            ->get("https://graph.facebook.com/{$c['graph_version']}/oauth/access_token", [
                'client_id'     => $c['app_id'],
                'client_secret' => $c['app_secret'],
                'redirect_uri'  => route('whatsapp.cloud.callback'),
                'code'          => $r->query('code'),
            ]);

        $token = $res->json('access_token');

        if (! $token) {
            return redirect()->route('whatsapp.cloud.index')->with(
                'wc_error',
                $res->json('error.message') ?: 'Gagal menukar code jadi access token.'
            );
        }

        ActivityLogService::log(
            action: 'wa_cloud_onboarded',
            description: 'Nomor bisnis tersambung ke WhatsApp Cloud API (Coexistence)',
            subjectType: 'whatsapp',
            subjectId: 'cloud',
            properties: [],
        );

        $wabaId = $this->discoverWabaId($token, $c);

        return redirect()->route('whatsapp.cloud.index')->with('wc_result', [
            'token'    => $token,
            'waba_id'  => $wabaId,
            'phone_id' => $this->discoverPhoneId($token, $c, $wabaId),
        ]);
    }

    private function discoverWabaId(string $token, array $c): ?string
    {
        $res = Http::timeout(self::TIMEOUT)
            ->get("https://graph.facebook.com/{$c['graph_version']}/debug_token", [
                'input_token'  => $token,
                'access_token' => $c['app_id'] . '|' . $c['app_secret'],
            ]);

        foreach ((array) $res->json('data.granular_scopes', []) as $scope) {
            if (($scope['scope'] ?? '') === 'whatsapp_business_messaging' && ! empty($scope['target_ids'])) {
                return (string) $scope['target_ids'][0];
            }
        }

        return null;
    }

    private function discoverPhoneId(string $token, array $c, ?string $wabaId): ?string
    {
        if (! $wabaId) {
            return null;
        }

        $res = Http::timeout(self::TIMEOUT)
            ->withToken($token)
            ->get("https://graph.facebook.com/{$c['graph_version']}/{$wabaId}/phone_numbers");

        return $res->json('data.0.id');
    }

    public function verify()
    {
        $c = config('services.whatsapp_cloud');

        if ($c['token'] === '' || $c['phone_id'] === '') {
            return response()->json([
                'ok'    => false,
                'error' => 'WA_CLOUD_TOKEN / WA_CLOUD_PHONE_ID belum diisi di .env.',
            ], 422);
        }

        $res = Http::timeout(self::TIMEOUT)
            ->withToken($c['token'])
            ->get("https://graph.facebook.com/{$c['graph_version']}/{$c['phone_id']}", [
                'fields' => 'display_phone_number,verified_name,quality_rating,platform_type',
            ]);

        if (! $res->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => $res->json('error.message') ?: 'Graph API menolak kredensial.',
            ], 422);
        }

        return response()->json(['ok' => true, 'number' => $res->json()]);
    }

    public function subscribe()
    {
        $c = config('services.whatsapp_cloud');

        if ($c['token'] === '' || $c['waba_id'] === '') {
            return response()->json([
                'ok'    => false,
                'error' => 'WA_CLOUD_TOKEN / WA_CLOUD_WABA_ID belum diisi di .env.',
            ], 422);
        }

        $res = Http::timeout(self::TIMEOUT)
            ->withToken($c['token'])
            ->post("https://graph.facebook.com/{$c['graph_version']}/{$c['waba_id']}/subscribed_apps");

        if (! $res->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => $res->json('error.message') ?: 'Gagal mendaftarkan app ke WABA.',
            ], 422);
        }

        ActivityLogService::log(
            action: 'wa_cloud_subscribed',
            description: 'App didaftarkan ke WhatsApp Business Account',
            subjectType: 'whatsapp',
            subjectId: 'cloud',
            properties: ['waba_id' => $c['waba_id']],
        );

        return response()->json(['ok' => true]);
    }
}
