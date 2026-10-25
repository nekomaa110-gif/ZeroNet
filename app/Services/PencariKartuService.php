<?php

namespace App\Services;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Support\Collection;

class PencariKartuService
{
    private ?Collection $peta = null;

    public function cari(array $ujung, ?string $batchKode = null, ?string $routerSlug = null): array
    {
        $username   = trim((string) ($ujung['username'] ?? ''));
        $password   = trim((string) ($ujung['password'] ?? ''));
        $no         = isset($ujung['no']) && $ujung['no'] !== '' ? (int) $ujung['no'] : null;
        $batchKode  = trim((string) $batchKode);
        $routerSlug = trim((string) $routerSlug);

        if (! empty($ujung['kartu']['sumber']) && ! empty($ujung['kartu']['id'])) {
            $kartu = $this->kartuPilihan((string) $ujung['kartu']['sumber'], (int) $ujung['kartu']['id']);

            if (! $kartu) {
                return $this->galat('Kartu yang dipilih sudah tidak ada. Cek ulang.');
            }

            if ($username !== '' && strcasecmp($username, $kartu['username']) !== 0) {
                return $this->galat('Kartu yang dipilih tidak cocok dengan Username. Cek ulang.');
            }

            return $this->satu($kartu, $password, $no, $username !== '' && $username !== $kartu['username']);
        }

        if ($username === '' && ($no === null || $batchKode === '')) {
            return $this->galat('Isi Username, atau No bersama Batch.');
        }

        $idBatch = [];
        if ($batchKode !== '') {
            $idBatch = VoucherBatch::where('code', $batchKode)->whereRaw('BINARY code = BINARY ?', [$batchKode])->pluck('id')->all();

            if (! $idBatch && ! $this->riwayatBatch($batchKode)->exists()) {
                return $this->galat("Batch {$batchKode} tidak ditemukan di panel maupun riwayat.");
            }
        }

        $idRouter = null;
        if ($routerSlug !== '') {
            $idRouter = Router::where('slug', $routerSlug)->value('id');

            if (! $idRouter) {
                return $this->galat("Router {$routerSlug} tidak ditemukan.");
            }
        }

        $hurufBeda = false;

        if ($username !== '') {
            $calon = $this->kumpulkan($batchKode, $idBatch, $idRouter, fn ($q) => $q->where('username', $username)->whereRaw('BINARY username = BINARY ?', [$username]));

            if (! $calon) {
                $calon     = $this->kumpulkan($batchKode, $idBatch, $idRouter, fn ($q) => $q->where('username', $username));
                $hurufBeda = $calon !== [];
            }
        } else {
            $idSemua = array_unique([...$idBatch, ...$this->riwayatBatch($batchKode)->whereNotNull('batch_id')->distinct()->pluck('batch_id')->all()]);

            if (! array_filter($idSemua, fn ($id) => $this->batchBernomor((int) $id))) {
                return $this->galat("Nomor kartu batch {$batchKode} belum dikunci. Cek lewat Username.");
            }

            $calon = $this->kumpulkan($batchKode, $idBatch, $idRouter, fn ($q) => $q->where('nomor', $no));
        }

        if (count($calon) > 1 && $password !== '') {
            $saring = array_values(array_filter($calon, fn ($k) => $k['password'] === $password));
            $calon  = $saring ?: $calon;
        }

        if (count($calon) > 1 && $no !== null) {
            $saring = array_values(array_filter($calon, fn ($k) => $k['nomor'] === $no));
            $calon  = $saring ?: $calon;
        }

        if (count($calon) > 1) {
            return [
                'status'     => 'ambigu',
                'kartu'      => null,
                'pilihan'    => array_map(fn ($k) => [
                    'batch_code'   => $k['batch_code'],
                    'router_name'  => $k['router_name'],
                    'router_slug'  => $k['router_slug'],
                    'nomor'        => $k['nomor'],
                    'status_label' => $k['status_label'],
                    'sumber'       => $k['sumber'],
                    'id'           => $k['voucher_id'],
                ], $calon),
                'peringatan' => [],
                'pesan'      => null,
            ];
        }

        if (! $calon) {
            return ['status' => 'tidak_ada', 'kartu' => null, 'pilihan' => [], 'peringatan' => [], 'pesan' => null];
        }

        return $this->satu($calon[0], $password, $no, $hurufBeda);
    }

    public function batchBernomor(int $batchId): bool
    {
        $hidup = Voucher::where('batch_id', $batchId);
        $hapus = DeletedVoucher::where('batch_id', $batchId);

        if (! (clone $hidup)->exists() && ! (clone $hapus)->exists()) {
            return false;
        }

        return ! (clone $hidup)->whereNull('nomor')->exists() && ! (clone $hapus)->whereNull('nomor')->exists();
    }

    private function satu(array $kartu, string $password, ?int $no, bool $hurufBeda): array
    {
        if ($no !== null && ! ($kartu['batch_id'] && $this->batchBernomor($kartu['batch_id']))) {
            return $this->galat("Nomor kartu batch {$kartu['batch_code']} belum dikunci. Kosongkan isian No.");
        }

        $peringatan = [];

        if ($hurufBeda) {
            $peringatan[] = "Huruf beda: tercatat {$kartu['username']}. Login di router gagal kalau hurufnya tidak sama persis.";
        }

        if ($no !== null && $kartu['nomor'] !== $no) {
            $peringatan[] = "{$kartu['username']} tercatat No {$kartu['nomor']}, Anda mengisi {$no}.";
        }

        if ($password !== '' && $kartu['password'] !== $password) {
            $peringatan[] = 'Password beda dengan catatan panel.';
        }

        return ['status' => 'ketemu', 'kartu' => $kartu, 'pilihan' => [], 'peringatan' => $peringatan, 'pesan' => null];
    }

    private function kumpulkan(string $batchKode, array $idBatch, ?int $idRouter, callable $saring): array
    {
        $hidup = Voucher::with('batch:id,code,router_name')
            ->when($batchKode !== '', fn ($q) => $q->whereIn('batch_id', $idBatch))
            ->when($idRouter, fn ($q) => $q->where('router_id', $idRouter))
            ->where($saring)
            ->orderBy('id')
            ->get();

        $hapus = DeletedVoucher::query()
            ->when($batchKode !== '', fn ($q) => $q->where('batch_code', $batchKode)->whereRaw('BINARY batch_code = BINARY ?', [$batchKode]))
            ->when($idRouter, fn ($q) => $q->where('router_id', $idRouter))
            ->where($saring)
            ->orderBy('voucher_id')
            ->get();

        return [...$hidup->map(fn (Voucher $v) => $this->dariHidup($v))->all(), ...$hapus->map(fn (DeletedVoucher $d) => $this->dariRiwayat($d))->all()];
    }

    private function riwayatBatch(string $batchKode)
    {
        return DeletedVoucher::where('batch_code', $batchKode)->whereRaw('BINARY batch_code = BINARY ?', [$batchKode]);
    }

    private function kartuPilihan(string $sumber, int $id): ?array
    {
        if ($sumber === 'hidup') {
            $v = Voucher::with('batch:id,code,router_name')->find($id);

            return $v ? $this->dariHidup($v) : null;
        }

        if ($sumber === 'terhapus') {
            $d = DeletedVoucher::where('voucher_id', $id)->first();

            return $d ? $this->dariRiwayat($d) : null;
        }

        return null;
    }

    private function dariHidup(Voucher $v): array
    {
        $router = $this->peta()->get($v->router_id);

        return [
            'sumber'       => 'hidup',
            'voucher_id'   => $v->id,
            'batch_id'     => $v->batch_id,
            'batch_code'   => $v->batch?->code,
            'router_id'    => $v->router_id,
            'router_name'  => $router?->name ?? $v->batch?->router_name,
            'router_slug'  => $router?->slug,
            'nomor'        => $v->nomor,
            'username'     => $v->username,
            'password'     => (string) $v->password,
            'status'       => $v->status_efektif,
            'status_label' => $v->status_label,
            'model'        => $v,
        ];
    }

    private function dariRiwayat(DeletedVoucher $d): array
    {
        $router = $d->router_id ? $this->peta()->get($d->router_id) : null;

        return [
            'sumber'       => 'terhapus',
            'voucher_id'   => $d->voucher_id,
            'batch_id'     => $d->batch_id,
            'batch_code'   => $d->batch_code,
            'router_id'    => $d->router_id,
            'router_name'  => $router?->name ?? $d->router_name,
            'router_slug'  => $router?->slug,
            'nomor'        => $d->nomor,
            'username'     => $d->username,
            'password'     => (string) $d->password,
            'status'       => (string) $d->status,
            'status_label' => $d->status_label,
            'model'        => $d,
        ];
    }

    private function peta(): Collection
    {
        return $this->peta ??= Router::query()->get(['id', 'slug', 'name'])->keyBy('id');
    }

    private function galat(string $pesan): array
    {
        return ['status' => 'galat', 'kartu' => null, 'pilihan' => [], 'peringatan' => [], 'pesan' => $pesan];
    }
}
