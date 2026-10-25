<?php

namespace App\Console\Commands;

use App\Jobs\SendVoucherWhatsApp;
use App\Models\RadCheck;
use App\Models\WaVoucherDelivery;
use App\Services\VoucherWaService;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class VoucherWaUji extends Command
{
    public const NOMOR_UJI = '6281200099999';

    protected $signature = 'voucher-wa:uji
        {username=root : Hanya akun root yang boleh diuji}
        {--jalan : Benar-benar mengirim (tanpa ini hanya menampilkan tujuan)}
        {--antrean : Kirim lewat antrean Redis dan worker sungguhan, lalu pantau hasilnya}
        {--tunggu=120 : Batas detik menunggu worker saat --antrean}';

    protected $description = 'Uji kirim voucher WhatsApp ke akun root secara terkendali';

    public function handle(VoucherWaService $service): int
    {
        $username = (string) $this->argument('username');

        if ($username !== 'root') {
            $this->error('Hanya akun root yang boleh dipakai untuk uji kirim voucher.');

            return self::FAILURE;
        }

        if (! RadCheck::where('username', $username)->where('attribute', 'Cleartext-Password')->exists()) {
            $this->error('Akun root tidak ada di radcheck.');

            return self::FAILURE;
        }

        $tujuan = $service->tujuan($username);
        $this->line('Tujuan: ' . ($tujuan['phone'] ?? '-') . ($tujuan['reason'] ? ' (' . $tujuan['reason'] . ')' : ''));

        if ($this->option('jalan') && $tujuan['phone'] !== self::NOMOR_UJI) {
            $this->error('Uji kirim hanya boleh ke ' . self::NOMOR_UJI . '. Aktifkan mode tes WA atau samakan nomor kontak root.');

            return self::FAILURE;
        }

        if (! $this->option('jalan')) {
            $this->info('Tidak ada yang dikirim. Tambahkan --jalan untuk mengirim.');

            return self::SUCCESS;
        }

        $kiriman = $service->buatKiriman($username, null);

        if ($kiriman->status !== WaVoucherDelivery::ANTRE) {
            return $this->hasil($kiriman);
        }

        if (! $this->option('antrean')) {
            $service->kirim($kiriman->id);

            return $this->hasil($kiriman->fresh());
        }

        SendVoucherWhatsApp::dispatch($kiriman->id);
        $this->line("Kiriman #{$kiriman->id} masuk antrean, menunggu worker...");

        $putaran = max(1, (int) ceil(((int) $this->option('tunggu')) / 2));

        for ($i = 0; $i < $putaran; $i++) {
            $kiriman->refresh();

            if (! $kiriman->isAktif()) {
                return $this->hasil($kiriman);
            }

            Sleep::for(2)->seconds();
        }

        $this->error("Kiriman #{$kiriman->id} masih {$kiriman->status}: worker antrean tidak memproses kiriman (restart laravel-queue?)");

        return self::FAILURE;
    }

    private function hasil(WaVoucherDelivery $kiriman): int
    {
        $this->line("Kiriman #{$kiriman->id}: {$kiriman->status}" . ($kiriman->reason ? " ({$kiriman->reason})" : '') . ($kiriman->message_id ? " id {$kiriman->message_id}" : ''));

        return $kiriman->status === WaVoucherDelivery::TERKIRIM ? self::SUCCESS : self::FAILURE;
    }
}
