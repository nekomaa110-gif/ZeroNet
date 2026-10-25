<?php

namespace App\Services;

use App\Exceptions\KirimUlangDitolak;
use App\Exceptions\VoucherMasihDikirim;
use App\Models\CustomerContact;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Models\WaVoucherDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoucherWaService
{
    public const VIEWPORT = ['width' => 402, 'scale' => 2];

    public const ALASAN_NOTIF_MATI      = 'Notifikasi WA sedang dimatikan';
    public const ALASAN_NOMOR_TES_KOSONG = 'Mode tes WA aktif tapi nomor tes kosong';
    public const ALASAN_TANPA_NOMOR     = 'User belum punya nomor WhatsApp';
    public const ALASAN_USER_HILANG     = 'User sudah dihapus';
    public const ALASAN_GAGAL_SIAPKAN   = 'Voucher gagal disiapkan sebelum dikirim';
    public const ALASAN_BUKAN_WA        = 'Nomor ini tidak terdaftar di WhatsApp';
    public const ALASAN_BELUM_TERSAMBUNG = 'Gateway WA belum tersambung';
    public const ALASAN_BATAS_KIRIM     = 'Batas kirim gateway tercapai, coba lagi sebentar';
    public const ALASAN_TENGGAT         = 'Gateway terlalu lama menyiapkan kiriman, belum terkirim';
    public const ALASAN_KUNCI_SALAH     = 'Kunci API gateway salah';
    public const ALASAN_CEK_NOMOR_GAGAL = 'Gateway gagal memeriksa nomor WhatsApp';
    public const ALASAN_ENDPOINT_BELUM  = 'Gateway belum mendukung kirim voucher';
    public const ALASAN_TIDAK_TERHUBUNG = 'Gateway WA tidak bisa dihubungi';
    public const ALASAN_TIDAK_PASTI     = 'Hasil kiriman tidak pasti; pesan mungkin sudah sampai';
    public const ALASAN_DIBATALKAN      = 'Dibatalkan karena voucher dikirim ulang';

    public function __construct(private WhatsAppService $wa) {}

    public function tujuan(string $username): array
    {
        if (! config('services.billing.wa_notifications')) {
            return ['phone' => null, 'reason' => self::ALASAN_NOTIF_MATI];
        }

        if (config('services.billing.wa_test_mode')) {
            $tes = (string) config('services.billing.wa_test_number');

            return $tes === ''
                ? ['phone' => null, 'reason' => self::ALASAN_NOMOR_TES_KOSONG]
                : ['phone' => WhatsAppService::normalizePhone($tes), 'reason' => null];
        }

        $phone = CustomerContact::where('username', $username)->value('phone');

        return $phone
            ? ['phone' => WhatsAppService::normalizePhone($phone), 'reason' => null]
            : ['phone' => null, 'reason' => self::ALASAN_TANPA_NOMOR];
    }

    public function buatKiriman(string $username, ?int $userId): WaVoucherDelivery
    {
        $tujuan = $this->tujuan($username);

        return WaVoucherDelivery::create([
            'username'   => $username,
            'phone'      => $tujuan['phone'],
            'status'     => $tujuan['phone'] ? WaVoucherDelivery::ANTRE : WaVoucherDelivery::GAGAL,
            'reason'     => $tujuan['reason'],
            'created_by' => $userId,
        ]);
    }

    public function kirimUlang(string $username, ?int $userId): WaVoucherDelivery
    {
        $kunci = Cache::lock('voucher-wa:' . mb_strtolower($username), 10);

        if (! $kunci->get()) {
            throw new VoucherMasihDikirim('Voucher masih dalam proses kirim');
        }

        try {
            return DB::transaction(function () use ($username, $userId) {
                $terakhir = WaVoucherDelivery::where('username', $username)->latest('id')->lockForUpdate()->first();

                if (! $terakhir) {
                    throw new KirimUlangDitolak('User ini belum pernah dikirimi voucher lewat panel');
                }

                if ($terakhir->status === WaVoucherDelivery::TERKIRIM) {
                    throw new KirimUlangDitolak('Voucher sudah terkirim, tidak perlu dikirim ulang');
                }

                $aktif = WaVoucherDelivery::where('username', $username)
                    ->whereIn('status', [WaVoucherDelivery::ANTRE, WaVoucherDelivery::DIPROSES])
                    ->lockForUpdate()
                    ->get();

                if ($aktif->contains(fn (WaVoucherDelivery $d) => ! $d->isBasi())) {
                    throw new VoucherMasihDikirim('Voucher masih dalam proses kirim');
                }

                foreach ($aktif as $d) {
                    $d->update(['status' => WaVoucherDelivery::DIBATALKAN, 'reason' => self::ALASAN_DIBATALKAN]);
                }

                return $this->buatKiriman($username, $userId);
            });
        } finally {
            $kunci->release();
        }
    }

    public function kirim(int $deliveryId): void
    {
        $d = DB::transaction(function () use ($deliveryId) {
            $d = WaVoucherDelivery::whereKey($deliveryId)->lockForUpdate()->first();

            if (! $d || $d->status !== WaVoucherDelivery::ANTRE) {
                return null;
            }

            $d->update(['status' => WaVoucherDelivery::DIPROSES]);

            return $d;
        });

        if (! $d) {
            return;
        }

        try {
            $password = RadCheck::where('username', $d->username)
                ->where('attribute', 'Cleartext-Password')
                ->value('value');

            if ($password === null) {
                $this->selesai($d, ['status' => WaVoucherDelivery::GAGAL, 'reason' => self::ALASAN_USER_HILANG, 'message_id' => null]);

                return;
            }

            $html    = $this->renderHtml($d->username, (string) $password);
            $caption = $this->caption($d->username);
        } catch (Throwable $e) {
            Log::warning('voucher_wa.siapkan_gagal', ['id' => $d->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            $this->selesai($d, ['status' => WaVoucherDelivery::GAGAL, 'reason' => self::ALASAN_GAGAL_SIAPKAN, 'message_id' => null]);

            return;
        }

        $hasil = $this->wa->sendImageHtml((string) $d->phone, $html, $caption, self::VIEWPORT);
        unset($html, $password);

        $this->selesai($d, $this->petakan($hasil));
    }

    public function petakan(array $hasil): array
    {
        $tidakPasti = ['status' => WaVoucherDelivery::BELUM_PASTI, 'reason' => self::ALASAN_TIDAK_PASTI, 'message_id' => null];

        if (($hasil['transport'] ?? null) !== null) {
            return $hasil['transport'] === 'refused'
                ? $this->gagal(self::ALASAN_TIDAK_TERHUBUNG)
                : $tidakPasti;
        }

        $body = is_array($hasil['body'] ?? null) ? $hasil['body'] : null;
        $http = (int) ($hasil['http'] ?? 0);

        if (($hasil['ok'] ?? false) === true) {
            return ['status' => WaVoucherDelivery::TERKIRIM, 'reason' => null, 'message_id' => self::potong((string) ($body['id'] ?? ''), 100) ?: null];
        }

        $kode  = (string) ($body['code'] ?? $body['error'] ?? '');
        $pesan = self::potong((string) ($body['error'] ?? ''), 100);

        return match (true) {
            $http === 429                       => $this->gagal(self::ALASAN_BATAS_KIRIM),
            $kode === 'number_not_on_whatsapp'  => $this->gagal(self::ALASAN_BUKAN_WA),
            $kode === 'wa_not_ready'            => $this->gagal(self::ALASAN_BELUM_TERSAMBUNG),
            $kode === 'check_failed'            => $this->gagal(self::ALASAN_CEK_NOMOR_GAGAL),
            $kode === 'unauthorized'            => $this->gagal(self::ALASAN_KUNCI_SALAH),
            $kode === 'render_failed'           => $this->gagal("Gambar voucher gagal dibuat ({$pesan})"),
            $kode === 'deadline_exceeded'       => $this->gagal(self::ALASAN_TENGGAT),
            $kode === 'invalid_request'         => $this->gagal("Permintaan ditolak gateway ({$pesan})"),
            $http === 404 && $body === null     => $this->gagal(self::ALASAN_ENDPOINT_BELUM),
            default                             => $tidakPasti,
        };
    }

    public function tandaiBelumPasti(int $deliveryId, string $reason): void
    {
        WaVoucherDelivery::whereKey($deliveryId)
            ->whereIn('status', [WaVoucherDelivery::ANTRE, WaVoucherDelivery::DIPROSES])
            ->update(['status' => WaVoucherDelivery::BELUM_PASTI, 'reason' => self::potong($reason, 255)]);
    }

    public function labelDrawer(?WaVoucherDelivery $d): array
    {
        if (! $d) {
            return ['status' => null, 'label' => '', 'ulang' => false, 'peringatan' => false];
        }

        if ($d->isAktif() && $d->isBasi()) {
            return ['status' => WaVoucherDelivery::BELUM_PASTI, 'label' => 'Belum pasti: proses kirim tidak selesai', 'ulang' => true, 'peringatan' => true];
        }

        return match ($d->status) {
            WaVoucherDelivery::TERKIRIM    => ['status' => $d->status, 'label' => 'Terkirim ' . ($d->sent_at ?? $d->updated_at)->translatedFormat('j M H:i') . ' ke ' . $d->phone, 'ulang' => false, 'peringatan' => false],
            WaVoucherDelivery::GAGAL       => ['status' => $d->status, 'label' => 'Gagal: ' . $d->reason, 'ulang' => true, 'peringatan' => false],
            WaVoucherDelivery::BELUM_PASTI => ['status' => $d->status, 'label' => 'Belum pasti: ' . $d->reason, 'ulang' => true, 'peringatan' => true],
            WaVoucherDelivery::DIBATALKAN  => ['status' => $d->status, 'label' => 'Dibatalkan', 'ulang' => true, 'peringatan' => false],
            default                        => ['status' => $d->status, 'label' => 'Sedang dikirim ke ' . $d->phone, 'ulang' => false, 'peringatan' => false],
        };
    }

    public function renderHtml(string $username, string $password): string
    {
        return view('user-hotspot.voucher-gambar', ['user' => ['username' => $username, 'password' => $password]])->render();
    }

    private function caption(string $username): string
    {
        $expiry = RadCheck::where('username', $username)->where('attribute', 'Expiration')->value('value');

        return MessageTemplateService::render('voucher_baru', [
            'name'     => CustomerContact::where('username', $username)->value('name') ?: $username,
            'username' => $username,
            'paket'    => RadUserGroup::where('username', $username)->value('groupname') ?: '-',
            'expire'   => $expiry ? Carbon::parse($expiry)->translatedFormat('d M Y') : 'tidak ada',
        ]);
    }

    private function selesai(WaVoucherDelivery $d, array $peta): void
    {
        $data = [
            'status'     => $peta['status'],
            'reason'     => $peta['reason'] === null ? null : self::potong($peta['reason'], 255),
            'message_id' => $peta['message_id'],
            'sent_at'    => $peta['status'] === WaVoucherDelivery::TERKIRIM ? now() : null,
        ];

        try {
            $d->update($data);
        } catch (Throwable $e) {
            Log::error('voucher_wa.simpan_status_gagal', ['id' => $d->id, 'status' => $data['status'], 'err' => mb_substr($e->getMessage(), 0, 200)]);

            return;
        }

        try {
            ActivityLogService::log(
                'voucher_wa_kirim',
                "Kirim voucher WA {$d->username}: {$data['status']}",
                'radius_user',
                $d->username,
                ['phone' => $d->phone, 'status' => $data['status'], 'reason' => $data['reason'], 'message_id' => $data['message_id']],
                null,
                $d->created_by,
            );
        } catch (Throwable $e) {
            Log::warning('voucher_wa.log_gagal', ['id' => $d->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private function gagal(string $reason): array
    {
        return ['status' => WaVoucherDelivery::GAGAL, 'reason' => $reason, 'message_id' => null];
    }

    private static function potong(string $teks, int $maks): string
    {
        return mb_substr($teks, 0, $maks);
    }
}
