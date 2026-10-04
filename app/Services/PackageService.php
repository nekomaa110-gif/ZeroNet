<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageAttribute;
use App\Models\RadGroupCheck;
use App\Models\RadGroupReply;
use App\Models\RadUserGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PackageService
{
    public const TIME_BLOCKED = ['Expiration', 'Max-All-Session', 'Auth-Type'];

    public const ATTRIBUTE_PRESETS = [
        'Mikrotik-Group',
        'Mikrotik-Rate-Limit',
        'Mikrotik-Recv-Limit',
        'Mikrotik-Xmit-Limit',
        'Mikrotik-Total-Limit',
        'Session-Timeout',
        'Idle-Timeout',
        'Simultaneous-Use',
        'Framed-IP-Address',
        'WISPr-Bandwidth-Max-Up',
        'WISPr-Bandwidth-Max-Down',
    ];

    public const OPERATORS = [':=', '=', '+=', '=='];

    public const TARGET_TABLES = [
        'radgroupreply',
        'radgroupcheck',
    ];

    public function all(): Collection
    {
        $packages = Package::all()->keyBy('groupname');

        $groupnames = collect(DB::select('SELECT groupname FROM radgroupcheck UNION SELECT groupname FROM radgroupreply'))
            ->pluck('groupname')
            ->merge($packages->keys())
            ->unique();

        $users = RadUserGroup::query()->selectRaw('groupname, COUNT(*) AS n')->groupBy('groupname')->pluck('n', 'groupname');
        $reply = RadGroupReply::whereIn('attribute', ['Mikrotik-Group', 'Mikrotik-Rate-Limit'])->get()->groupBy('groupname');
        $check = RadGroupCheck::whereIn('attribute', ['Simultaneous-Use', 'Auth-Type'])->get()->groupBy('groupname');
        $nilai = fn (?Collection $rows, string $attr) => $rows?->firstWhere('attribute', $attr)?->value;

        return $groupnames->map(function (string $groupname) use ($packages, $users, $reply, $check, $nilai) {
            $pkg = $packages->get($groupname);
            $r   = $reply->get($groupname);
            $c   = $check->get($groupname);

            return [
                'id'            => $pkg?->id,
                'groupname'     => $groupname,
                'nama'          => $pkg?->customerName() ?? $groupname,
                'display_name'  => $pkg?->display_name,
                'speed_label'   => $pkg?->speed_label,
                'description'   => $pkg?->description,
                'is_active'     => $pkg ? $pkg->is_active : $nilai($c, 'Auth-Type') !== 'Reject',
                'profil'        => $nilai($r, 'Mikrotik-Group'),
                'rate'          => $nilai($r, 'Mikrotik-Rate-Limit'),
                'perangkat'     => $nilai($c, 'Simultaneous-Use'),
                'user_count'    => (int) ($users[$groupname] ?? 0),
                'is_public'     => (bool) $pkg?->is_public,
                'price'         => $pkg?->price,
                'show_price'    => (bool) $pkg?->show_price,
                'validity_days' => $pkg?->validity_days,
                'public_description' => $pkg?->public_description,
                'sort_order_asli'    => $pkg?->sort_order ?? 0,
                'sort_order'    => $pkg?->sort_order ?? PHP_INT_MAX,
                'is_legacy'     => $pkg === null,
            ];
        })->sortBy([['is_legacy', 'asc'], ['user_count', 'desc'], ['sort_order', 'asc'], ['groupname', 'asc']])->values();
    }

    public static function kunciProfilRouter(string $slug): string
    {
        return "packages.profil.{$slug}";
    }

    public static function lupakanProfilRouter(string $slug): void
    {
        Cache::forget(self::kunciProfilRouter($slug));
    }

    public function simpanRadius(array $data): Package
    {
        return DB::transaction(function () use ($data) {
            $groupname = $data['groupname'];
            $package   = Package::where('groupname', $groupname)->first()
                ?? ($this->existsInRadius($groupname) ? $this->importFromRadius($groupname) : null);

            $kolom = [
                'display_name' => $data['display_name'] ?? null,
                'speed_label'  => $data['speed_label'] ?? null,
                'description'  => $data['description'] ?? null,
                'is_active'    => (bool) ($data['is_active'] ?? true),
            ] + $this->publicFields($data);

            if ($package) {
                $package->update($kolom);
            } else {
                $package = Package::create(['groupname' => $groupname] + $kolom);
            }

            $lain = $package->attributes()
                ->whereNotIn('attribute', ['Mikrotik-Group', 'Simultaneous-Use'])
                ->get()
                ->map(fn ($a) => $a->only(['attribute', 'op', 'value', 'target_table']))
                ->all();

            $inti = [['attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => $data['profil'], 'target_table' => 'radgroupreply']];
            if (! empty($data['perangkat'])) {
                $inti[] = ['attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => (string) (int) $data['perangkat'], 'target_table' => 'radgroupcheck'];
            }

            $package->attributes()->delete();
            $this->saveAttributes($package, [...$inti, ...$lain]);
            $this->syncToRadius($package->fresh('attributes'));

            return $package->fresh();
        });
    }

    public function find(Package $package): Package
    {
        return $package->loadMissing('attributes');
    }

    public function create(array $data): Package
    {
        return DB::transaction(function () use ($data) {
            $package = Package::create([
                'groupname'    => $data['groupname'],
                'display_name' => $data['display_name'] ?? null,
                'speed_label'  => $data['speed_label'] ?? null,
                'description'  => $data['description'] ?? null,
                'is_active'    => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ] + $this->publicFields($data));

            $this->saveAttributes($package, $data['attributes'] ?? []);
            $this->syncToRadius($package);

            return $package;
        });
    }

    public function update(Package $package, array $data): void
    {
        DB::transaction(function () use ($package, $data) {
            $package->update([
                'display_name' => $data['display_name'] ?? null,
                'speed_label'  => $data['speed_label'] ?? null,
                'description'  => $data['description'] ?? null,
                'is_active'    => isset($data['is_active']) ? (bool) $data['is_active'] : false,
            ] + $this->publicFields($data));

            $package->attributes()->delete();
            $this->saveAttributes($package, $data['attributes'] ?? []);
            $this->syncToRadius($package);
        });
    }

    private function publicFields(array $data): array
    {
        return [
            'price'              => ($data['price'] ?? null) !== null ? (int) $data['price'] : null,
            'show_price'         => (bool) ($data['show_price'] ?? false),
            'validity_days'      => ($data['validity_days'] ?? null) !== null ? (int) $data['validity_days'] : null,
            'public_description' => $data['public_description'] ?? null,
            'is_public'          => (bool) ($data['is_public'] ?? false),
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
        ];
    }

    public function toggle(Package $package): void
    {
        DB::transaction(function () use ($package) {
            $newActive = ! $package->is_active;
            $package->update(['is_active' => $newActive]);

            if ($newActive) {
                RadGroupCheck::where('groupname', $package->groupname)
                    ->where('attribute', 'Auth-Type')
                    ->delete();
            } else {
                RadGroupCheck::updateOrCreate(
                    ['groupname' => $package->groupname, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject']
                );
            }
        });
    }

    public function delete(Package $package): void
    {
        DB::transaction(function () use ($package) {
            $this->clearFromRadius($package->groupname);
            $package->delete();
        });
    }

    public function exists(string $groupname): bool
    {
        return Package::where('groupname', $groupname)->exists();
    }

    public function existsInRadius(string $groupname): bool
    {
        return RadGroupCheck::where('groupname', $groupname)->exists()
            || RadGroupReply::where('groupname', $groupname)->exists();
    }

    public function importFromRadius(string $groupname): Package
    {
        return DB::transaction(function () use ($groupname) {
            $authType = RadGroupCheck::where('groupname', $groupname)
                ->where('attribute', 'Auth-Type')
                ->value('value');

            $isActive = ($authType === null) || ($authType === 'Accept');

            $package = Package::create([
                'groupname'   => $groupname,
                'description' => null,
                'is_active'   => $isActive,
            ]);

            $sortOrder = 0;

            RadGroupCheck::where('groupname', $groupname)
                ->where('attribute', '!=', 'Auth-Type')
                ->get()
                ->each(function ($row) use ($package, &$sortOrder) {
                    if (in_array($row->attribute, self::TIME_BLOCKED, true)) {
                        return;
                    }
                    $package->attributes()->create([
                        'attribute'    => $row->attribute,
                        'op'           => $row->op,
                        'value'        => $row->value,
                        'target_table' => 'radgroupcheck',
                        'sort_order'   => $sortOrder++,
                    ]);
                });

            RadGroupReply::where('groupname', $groupname)
                ->get()
                ->each(function ($row) use ($package, &$sortOrder) {
                    if (in_array($row->attribute, self::TIME_BLOCKED, true)) {
                        return;
                    }
                    $package->attributes()->create([
                        'attribute'    => $row->attribute,
                        'op'           => $row->op,
                        'value'        => $row->value,
                        'target_table' => 'radgroupreply',
                        'sort_order'   => $sortOrder++,
                    ]);
                });

            return $package;
        });
    }

    private function saveAttributes(Package $package, array $attributes): void
    {
        foreach ($attributes as $i => $attr) {
            if (empty($attr['attribute'])) {
                continue;
            }
            if (in_array($attr['attribute'], self::TIME_BLOCKED, true)) {
                continue;
            }
            $package->attributes()->create([
                'attribute'    => trim($attr['attribute']),
                'op'           => $attr['op'] ?? ':=',
                'value'        => trim($attr['value'] ?? ''),
                'target_table' => $attr['target_table'] ?? 'radgroupreply',
                'sort_order'   => (int) $i,
            ]);
        }
    }

    private function syncToRadius(Package $package): void
    {
        $groupname = $package->groupname;

        $this->clearFromRadius($groupname);

        if (! $package->is_active) {
            RadGroupCheck::create([
                'groupname' => $groupname,
                'attribute' => 'Auth-Type',
                'op'        => ':=',
                'value'     => 'Reject',
            ]);
        }

        foreach ($package->attributes as $attr) {
            match ($attr->target_table) {
                'radgroupreply' => RadGroupReply::create([
                    'groupname' => $groupname,
                    'attribute' => $attr->attribute,
                    'op'        => $attr->op,
                    'value'     => $attr->value,
                ]),
                'radgroupcheck' => RadGroupCheck::create([
                    'groupname' => $groupname,
                    'attribute' => $attr->attribute,
                    'op'        => $attr->op,
                    'value'     => $attr->value,
                ]),
                default => null,
            };
        }
    }

    private function clearFromRadius(string $groupname): void
    {
        RadGroupCheck::where('groupname', $groupname)->delete();
        RadGroupReply::where('groupname', $groupname)->delete();
    }
}
