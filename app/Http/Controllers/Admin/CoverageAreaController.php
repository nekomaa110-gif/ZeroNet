<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoverageArea;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CoverageAreaController extends Controller
{
    public function index(): View
    {
        return view('coverage-areas.index', [
            'areas' => CoverageArea::ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $area = CoverageArea::create($data);

        ActivityLogService::log('create', "menambah area layanan: {$area->name}", 'coverage_area', (string) $area->id);

        return redirect()->route('coverage-areas.index')
            ->with('success', "Area \"{$area->name}\" berhasil ditambahkan.");
    }

    public function update(Request $request, CoverageArea $coverage_area): RedirectResponse
    {
        $coverage_area->update($this->validated($request));

        ActivityLogService::log('update', "mengubah area layanan: {$coverage_area->name}", 'coverage_area', (string) $coverage_area->id);

        return redirect()->route('coverage-areas.index')
            ->with('success', "Area \"{$coverage_area->name}\" berhasil diperbarui.");
    }

    public function toggle(CoverageArea $coverage_area): RedirectResponse
    {
        $coverage_area->update(['is_active' => ! $coverage_area->is_active]);
        $state = $coverage_area->is_active ? 'ditampilkan' : 'disembunyikan';

        ActivityLogService::log('update', "{$state} area layanan: {$coverage_area->name}", 'coverage_area', (string) $coverage_area->id);

        return back()->with('success', "Area \"{$coverage_area->name}\" {$state} di website.");
    }

    public function destroy(CoverageArea $coverage_area): RedirectResponse
    {
        $name = $coverage_area->name;
        $coverage_area->delete();

        ActivityLogService::log('delete', "menghapus area layanan: {$name}", 'coverage_area', (string) $coverage_area->id);

        return redirect()->route('coverage-areas.index')
            ->with('success', "Area \"{$name}\" berhasil dihapus.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'status'     => ['required', Rule::in(CoverageArea::STATUSES)],
            'note'       => ['nullable', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['nullable', 'boolean'],
        ], [
            'name.required'   => 'Nama area wajib diisi.',
            'status.required' => 'Status area wajib dipilih.',
            'status.in'       => 'Status area tidak valid.',
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active']  = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
