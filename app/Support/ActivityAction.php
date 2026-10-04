<?php

namespace App\Support;

class ActivityAction
{
    private const MAP = [
        'create_user' => ['Tambah user', 'ok'],
        'update_user' => ['Edit user', 'info'],
        'delete_user' => ['Hapus user', 'err'],
        'toggle_user' => ['Ubah status user', 'warn'],
        'create_package' => ['Tambah paket', 'ok'],
        'update_package' => ['Edit paket', 'info'],
        'delete_package' => ['Hapus paket', 'err'],
        'toggle_package' => ['Ubah status paket', 'warn'],
        'update_profile' => ['Edit profil', 'info'],
        'update_password' => ['Ganti password', 'brand'],
        'generate_voucher' => ['Buat voucher', 'ok'],
        'print_voucher' => ['Cetak voucher', 'brand'],
        'disable_voucher' => ['Nonaktifkan voucher', 'warn'],
        'enable_voucher' => ['Aktifkan voucher', 'info'],
        'delete_voucher' => ['Hapus voucher', 'err'],
        'voucher_sinkron' => ['Sinkron voucher', ''],
        'radacct.reconcile' => ['Rekonsiliasi sesi', ''],
        'login' => ['Login', 'ok'],
        'logout' => ['Logout', ''],
        'login_failed' => ['Login gagal', 'err'],
        '2fa_passed' => ['Lolos 2FA', 'ok'],
        'password_confirm_failed' => ['Verifikasi password gagal', 'warn'],
        'wa_send' => ['Kirim WA', 'info'],
        'wa_broadcast' => ['Broadcast WA', 'info'],
        'wa_contact_create' => ['Tambah kontak WA', 'ok'],
        'wa_gateway_reconnect' => ['Sambung ulang gateway', 'warn'],
        'wa_gateway_reset' => ['Reset gateway', 'warn'],
        'wa_cloud_onboarded' => ['Hubungkan Cloud API', 'ok'],
        'loadbalance_update' => ['Ubah load balance', 'warn'],
        'reboot_router' => ['Reboot router', 'warn'],
        'customer_login' => ['Pelanggan login', 'brand'],
        'customer_login_failed' => ['Pelanggan gagal login', 'warn'],
        'customer_renew_request' => ['Pelanggan minta perpanjang', 'info'],
        'customer_proof_upload' => ['Pelanggan unggah bukti', 'info'],
        'customer_phone_register' => ['Pelanggan daftar HP', 'ok'],
        'extend' => ['Perpanjang', 'ok'],
        'create' => ['Buat', 'ok'],
        'update' => ['Ubah', 'info'],
        'delete' => ['Hapus', 'err'],
    ];

    public static function label(?string $action): string
    {
        return self::MAP[$action][0] ?? ucfirst(str_replace(['_', '.'], ' ', (string) $action));
    }

    public static function tone(?string $action): string
    {
        return self::MAP[$action][1] ?? '';
    }

    public static function all(): array
    {
        return array_map(fn ($v) => $v[0], self::MAP);
    }
}
