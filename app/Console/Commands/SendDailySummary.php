<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\RadAcct;
use App\Services\BillingNotificationService;
use App\Services\MessageTemplateService;
use App\Services\RadiusUserService;
use App\Services\VoucherSalesReport;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDailySummary extends Command
{
    protected $signature = 'wa:daily-summary
        {--dry : Tampilkan isi pesan tanpa mengirim}';
    protected $description = 'Kirim ringkasan harian (pelanggan, tagihan, pemasukan, penjualan voucher) ke WhatsApp admin';

    public function __construct(
        private BillingNotificationService $notifier,
        private RadiusUserService $radiusUsers,
        private VoucherSalesReport $penjualan,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today     = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $users = $this->radiusUsers->stats();

        $paidYesterday = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('confirmed_at', [$yesterday, $yesterday->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as jml, COALESCE(SUM(amount), 0) as nilai')
            ->first();

        $kemarin = ['dari' => $yesterday->format('Y-m-d'), 'sampai' => $yesterday->format('Y-m-d')];
        $voucher = $this->penjualan->total($kemarin);
        $perRouter = collect($this->penjualan->rekap($kemarin, ['router_name']))
            ->map(fn ($r) => "{$r['router_name']}: {$r['transaksi']} ({$this->rupiah($r['omzet'])})")
            ->implode("\n");

        $unpaid = Invoice::where('status', Invoice::STATUS_UNPAID)
            ->selectRaw('COUNT(*) as jml, COALESCE(SUM(amount), 0) as nilai')
            ->first();

        $message = MessageTemplateService::render('admin_ringkasan_harian', [

            'tanggal'             => $today->locale('id')->translatedFormat('l')
                                     . ', ' . $today->locale('en')->translatedFormat('d M Y'),

            'user_aktif'          => $users['active'],
            'user_total'          => $users['total'],
            'user_expired'        => $users['expired'],
            'user_disabled'       => $users['disabled'],
            'sesi_online'         => RadAcct::whereNull('acctstoptime')->count(),

            'habis_hari_ini'      => $this->expiringOn($today),
            'habis_besok'         => $this->expiringOn($today->copy()->addDay()),

            'invoice_pending'     => Invoice::where('status', Invoice::STATUS_PENDING)->count(),
            'invoice_draft'       => Invoice::where('status', Invoice::STATUS_DRAFT)->count(),
            'invoice_belum_bayar' => (int) $unpaid->jml,
            'nilai_belum_bayar'   => $this->rupiah((int) $unpaid->nilai),
            'invoice_nunggak'     => Invoice::whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
                                        ->whereNotNull('due_date')
                                        ->where('due_date', '<', $today)
                                        ->count(),

            'bayar_kemarin'       => (int) $paidYesterday->jml,
            'nilai_bayar_kemarin' => $this->rupiah((int) $paidYesterday->nilai),

            'voucher_kemarin'       => $voucher['transaksi'],
            'omzet_voucher_kemarin' => $this->rupiah($voucher['omzet']),
            'voucher_per_router'    => $perRouter !== '' ? $perRouter : 'Tidak ada penjualan tercatat.',

            'panel_url'           => rtrim((string) config('app.url'), '/'),
        ]);

        if ($this->option('dry')) {
            $this->line($message);
            $this->newLine();
            $this->info('[DRY] Tidak dikirim.');
            return self::SUCCESS;
        }

        $this->notifier->notifyAdmin($message, 'daily_summary');
        $this->info('Ringkasan harian masuk antrean kirim.');

        return self::SUCCESS;
    }

    private function expiringOn(Carbon $date): int
    {
        return DB::table('radcheck')
            ->where('radcheck.attribute', 'Expiration')
            ->whereRaw(
                "DATE(COALESCE(
                    STR_TO_DATE(radcheck.value, '%d %b %Y %H:%i:%s'),
                    STR_TO_DATE(radcheck.value, '%d %b %Y')
                 )) = ?",
                [$date->format('Y-m-d')],
            )
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('radcheck as rc_rej')
                  ->whereColumn('rc_rej.username', 'radcheck.username')
                  ->where('rc_rej.attribute', 'Auth-Type')
                  ->where('rc_rej.value', 'Reject');
            })
            ->distinct()
            ->count('radcheck.username');
    }

    private function rupiah(int $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
