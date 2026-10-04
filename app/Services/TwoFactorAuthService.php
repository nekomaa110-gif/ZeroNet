<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        $issuer = config('app.name', 'ZeroNet');

        return $this->google2fa->getQRCodeUrl(
            $issuer,
            $user->username . '@' . preg_replace('/\s+/', '', $issuer),
            $secret,
        );
    }

    public function qrCodeSvg(string $otpAuthUrl, int $size = 220): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($otpAuthUrl);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::lower(Str::random(5)) . '-' . Str::lower(Str::random(5));
        }

        return $codes;
    }

    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $codes);
    }

    public function consumeRecoveryCode(array $hashedCodes, string $input): ?array
    {
        $input = Str::lower(trim($input));

        foreach ($hashedCodes as $i => $hashed) {
            if (Hash::check($input, $hashed)) {
                unset($hashedCodes[$i]);
                return array_values($hashedCodes);
            }
        }

        return null;
    }
}
