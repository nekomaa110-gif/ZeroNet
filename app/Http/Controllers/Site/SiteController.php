<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\CoverageArea;
use App\Models\CustomerContact;
use App\Models\Package;
use App\Models\RadAcct;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(): View
    {
        return view('site.home', [
            'packages' => $this->publicPackages()->take(3),
            'areas'    => $this->publicAreas(),
        ]);
    }

    public function packages(): View
    {
        return view('site.paket', [
            'packages' => $this->publicPackages(),
        ]);
    }

    public function coverage(): View
    {
        return view('site.coverage', [
            'areas' => $this->publicAreas(),
        ]);
    }

    public function help(): View
    {
        return view('site.bantuan');
    }

    public function status(): View
    {
        abort_unless(config('site.status_check_enabled'), 404);

        return view('site.status', ['result' => null]);
    }

    public function statusLookup(Request $request): View|RedirectResponse
    {
        abort_unless(config('site.status_check_enabled'), 404);

        $data = $request->validate([
            'identitas' => ['required', 'string', 'min:3', 'max:64'],
        ], [
            'identitas.required' => 'Masukkan username atau nomor HP yang terdaftar.',
            'identitas.min'      => 'Data yang dimasukkan terlalu pendek.',
        ]);

        $username = $this->resolveUsername(trim($data['identitas']));

        if ($username === null) {
            return back()
                ->withInput()
                ->withErrors(['identitas' => 'Data tidak ditemukan. Periksa kembali penulisannya, atau hubungi admin lewat WhatsApp.']);
        }

        return view('site.status', ['result' => $this->buildStatus($username)]);
    }

    private function publicPackages()
    {
        return Package::query()
            ->where('is_public', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();
    }

    private function publicAreas()
    {
        return CoverageArea::published()->ordered()->get();
    }

    private function resolveUsername(string $input): ?string
    {
        if (preg_match('/^[0-9+\-\s()]{9,}$/', $input)) {
            $phone = WhatsAppService::normalizePhone($input);

            $username = CustomerContact::where('phone', $phone)->value('username');

            if ($username !== null) {
                return (string) $username;
            }
        }

        $exists = RadCheck::where('username', $input)->exists()
            || CustomerContact::where('username', $input)->exists();

        return $exists ? $input : null;
    }

    private function buildStatus(string $username): array
    {
        $expiry = $this->expiryOf($username);
        $contact = CustomerContact::where('username', $username)->first();

        $groupname = RadUserGroup::where('username', $username)
            ->orderBy('priority')
            ->value('groupname');

        $package = $groupname
            ? Package::where('groupname', $groupname)->first()
            : null;

        $daysLeft = $expiry
            ? (int) Carbon::now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false)
            : null;

        return [
            'name'     => $this->mask($contact->name ?? $username),
            'username' => $this->mask($username),
            'package'  => $package?->customerName() ?? ($groupname ? 'Paket Langganan' : 'Belum ada paket'),
            'active'   => $expiry ? $expiry->isFuture() : null,
            'expiry'   => $expiry,
            'daysLeft' => $daysLeft,
            'online'   => $this->isOnline($username),
        ];
    }

    private function expiryOf(string $username): ?Carbon
    {
        $value = RadCheck::where('username', $username)
            ->where('attribute', 'Expiration')
            ->value('value');

        if (! $value) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d M Y H:i:s', trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function isOnline(string $username): bool
    {
        return RadAcct::where('username', $username)
            ->whereNull('acctstoptime')
            ->exists();
    }

    private function mask(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return implode(' ', array_map(static function (string $word): string {
            $len = mb_strlen($word);

            if ($len <= 2) {
                return mb_substr($word, 0, 1).'*';
            }

            return mb_substr($word, 0, 2).str_repeat('*', min($len - 2, 6));
        }, $parts));
    }
}
