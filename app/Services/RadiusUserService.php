<?php

namespace App\Services;

use App\Models\CustomerContact;
use App\Models\CustomerRememberToken;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\RadGroupCheck;
use App\Models\RadReply;
use App\Models\RadUserGroup;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RadiusUserService
{
    public const STATS_CACHE_KEY = 'dashboard.stats';

    public static function flushStatsCache(): void
    {
        Cache::forget(self::STATS_CACHE_KEY);
    }

    public function paginate(string $search = '', int $perPage = 15, string $group = '', string $status = '', string $sort = 'up'): LengthAwarePaginator
    {
        $query = RadCheck::query()
            ->select('radcheck.username')
            ->where('radcheck.attribute', 'Cleartext-Password');

        if ($group) {
            $query->join('radusergroup', 'radcheck.username', '=', 'radusergroup.username')
                  ->where('radusergroup.groupname', $group);
        }

        if ($search) {
            $query->where('radcheck.username', 'like', "%{$search}%");
        }

        $effectiveExpiryExpr = "
            (SELECT COALESCE(
                STR_TO_DATE(rc_exp.value, '%d %b %Y %H:%i:%s'),
                STR_TO_DATE(rc_exp.value, '%d %b %Y')
             )
             FROM radcheck rc_exp
             WHERE rc_exp.username = radcheck.username AND rc_exp.attribute = 'Expiration'
             LIMIT 1)
        ";

        $hasReject = function ($q) {
            $q->from('radcheck as rc_rej')
              ->whereColumn('rc_rej.username', 'radcheck.username')
              ->where('rc_rej.attribute', 'Auth-Type')
              ->where('rc_rej.value', 'Reject');
        };

        if ($status === 'nonaktif') {
            $query->whereExists($hasReject);
        } elseif ($status === 'aktif') {
            $query->whereNotExists($hasReject)
                  ->where(function ($q) use ($effectiveExpiryExpr) {
                      $q->whereRaw("$effectiveExpiryExpr >= NOW()")
                        ->orWhereRaw("$effectiveExpiryExpr IS NULL");
                  });
        } elseif ($status === 'expired') {
            $query->whereRaw("$effectiveExpiryExpr < NOW()");
        }

        $query->distinct();

        $total = (clone $query)->count();
        $page  = LengthAwarePaginator::resolveCurrentPage();

        $sort = $sort === 'down' ? 'down' : 'up';

        if (in_array($status, ['aktif', 'expired'], true)) {
            $query->selectRaw("COALESCE($effectiveExpiryExpr, '9999-12-31 23:59:59') as eff_expiry")
                  ->orderBy('eff_expiry', $sort === 'up' ? 'desc' : 'asc');
        } else {
            $query->orderBy('radcheck.username', $sort === 'up' ? 'asc' : 'desc');
        }

        $usernames = $query->forPage($page, $perPage)->get()->pluck('username');

        $groups         = RadUserGroup::whereIn('username', $usernames)->pluck('groupname', 'username');
        $radExpiries    = RadCheck::whereIn('username', $usernames)->where('attribute', 'Expiration')->pluck('value', 'username');
        $blocked        = RadCheck::whereIn('username', $usernames)->where('attribute', 'Auth-Type')->where('value', 'Reject')->pluck('username')->flip();
        $openInvoices   = Invoice::whereIn('username', $usernames)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->get(['id', 'username', 'amount', 'status'])
            ->groupBy(fn (Invoice $i) => mb_strtolower($i->username));

        $items = $usernames->map(function ($username) use ($groups, $radExpiries, $blocked, $openInvoices) {
            $expiryAt = $radExpiries->has($username)
                ? Carbon::parse($radExpiries[$username])
                : null;

            return [
                'username'      => $username,
                'group'         => $groups->get($username, '-'),
                'expiry'        => $expiryAt?->format('d M Y') ?? '-',
                'expiry_at'     => $expiryAt?->format('Y-m-d H:i:s'),
                'active'        => ! $blocked->has($username),
                'open_invoices' => $openInvoices->get(mb_strtolower($username), collect())->values(),
            ];
        });

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    public function find(string $username): array
    {
        $password = RadCheck::where('username', $username)
            ->where('attribute', 'Cleartext-Password')
            ->value('value');

        if (! $password) {
            abort(404, 'User tidak ditemukan.');
        }

        $group = RadUserGroup::where('username', $username)->value('groupname');

        $expiryRaw = RadCheck::where('username', $username)
            ->where('attribute', 'Expiration')
            ->value('value');

        $maxData = RadReply::where('username', $username)
            ->where('attribute', 'WISPr-Bandwidth-Max-Down')
            ->value('value');

        return [
            'username'     => $username,
            'password'     => $password,
            'group'        => $group,
            'expiry'       => $expiryRaw ? Carbon::parse($expiryRaw)->format('d M Y') : '-',
            'expiry_input' => $expiryRaw ? Carbon::parse($expiryRaw)->format('Y-m-d') : '',
            'max_down'     => $maxData,
        ];
    }

    public function create(array $data): void
    {
        DB::transaction(function () use ($data) {
            RadCheck::create([
                'username'  => $data['username'],
                'attribute' => 'Cleartext-Password',
                'op'        => ':=',
                'value'     => $data['password'],
            ]);

            if (! empty($data['group'])) {
                RadUserGroup::create([
                    'username'  => $data['username'],
                    'groupname' => $data['group'],
                    'priority'  => 1,
                ]);
            }

            if (! empty($data['expiry'])) {
                RadCheck::create([
                    'username'  => $data['username'],
                    'attribute' => 'Expiration',
                    'op'        => ':=',
                    'value'     => self::toRadiusDate($data['expiry']),
                ]);
            }
        });

        self::flushStatsCache();
    }

    public function update(string $username, array $data): void
    {
        DB::transaction(function () use ($username, $data) {
            if (! empty($data['password'])) {
                RadCheck::updateOrCreate(
                    ['username' => $username, 'attribute' => 'Cleartext-Password'],
                    ['op' => ':=', 'value' => $data['password']]
                );
            }

            RadUserGroup::where('username', $username)->delete();
            if (! empty($data['group'])) {
                RadUserGroup::create([
                    'username'  => $username,
                    'groupname' => $data['group'],
                    'priority'  => 1,
                ]);
            }

            RadCheck::where('username', $username)->where('attribute', 'Expiration')->delete();
            if (! empty($data['expiry'])) {
                RadCheck::create([
                    'username'  => $username,
                    'attribute' => 'Expiration',
                    'op'        => ':=',
                    'value'     => self::toRadiusDate($data['expiry']),
                ]);
            }
        });

        self::flushStatsCache();
    }

    public function extend(string $username, int $days = 30): array
    {
        $result = DB::transaction(function () use ($username, $days) {
            $exists = RadCheck::where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->exists();

            if (! $exists) {
                abort(404, 'User tidak ditemukan.');
            }

            $row = RadCheck::where('username', $username)
                ->where('attribute', 'Expiration')
                ->lockForUpdate()
                ->first();

            $current = $row && $row->value ? Carbon::parse($row->value) : null;
            $base    = $current && $current->isFuture() ? $current->copy() : Carbon::now();
            $new     = $base->addDays($days)->setTime(23, 59, 59);
            $value   = $new->format('d M Y H:i:s');

            if ($row) {
                $row->update(['op' => ':=', 'value' => $value]);
            } else {
                RadCheck::create([
                    'username'  => $username,
                    'attribute' => 'Expiration',
                    'op'        => ':=',
                    'value'     => $value,
                ]);
            }

            return [
                'from'         => $current,
                'to'           => $new,
                'from_expired' => $current === null || $current->isPast(),
            ];
        });

        self::flushStatsCache();

        return $result;
    }

    public function delete(string $username): void
    {
        DB::transaction(function () use ($username) {
            RadCheck::where('username', $username)->delete();
            RadReply::where('username', $username)->delete();
            RadUserGroup::where('username', $username)->delete();

            CustomerContact::where('username', $username)->delete();
            CustomerRememberToken::where('username', $username)->delete();

            $catatan = '[dibatalkan otomatis: user hotspot dihapus ' . now()->format('Y-m-d H:i') . ']';

            Invoice::where('username', $username)
                ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
                ->get()
                ->each(fn (Invoice $invoice) => $invoice->update([
                    'status' => Invoice::STATUS_CANCELLED,
                    'notes'  => trim($invoice->notes . ' ' . $catatan),
                ]));
        });

        self::flushStatsCache();
    }

    public function toggle(string $username): bool
    {
        $reject = RadCheck::where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->first();

        if ($reject) {
            $reject->delete();
            self::flushStatsCache();
            return true;
        }

        RadCheck::create([
            'username'  => $username,
            'attribute' => 'Auth-Type',
            'op'        => ':=',
            'value'     => 'Reject',
        ]);

        self::flushStatsCache();

        return false;
    }

    public function isActive(string $username): bool
    {
        return ! RadCheck::where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->exists();
    }

    public function stats(): array
    {
        $total = RadCheck::where('attribute', 'Cleartext-Password')->distinct()->count('username');

        $disabled = RadCheck::where('attribute', 'Auth-Type')->where('value', 'Reject')->distinct()->count('username');

        $effectiveExpiryExpr = "
            (SELECT COALESCE(
                STR_TO_DATE(rc_exp.value, '%d %b %Y %H:%i:%s'),
                STR_TO_DATE(rc_exp.value, '%d %b %Y')
             )
             FROM radcheck rc_exp
             WHERE rc_exp.username = radcheck.username AND rc_exp.attribute = 'Expiration'
             LIMIT 1)
        ";

        $expired = RadCheck::query()
            ->where('radcheck.attribute', 'Cleartext-Password')
            ->whereRaw("$effectiveExpiryExpr < NOW()")
            ->whereNotExists(function ($q) {
                $q->from('radcheck as rc_rej')
                  ->whereColumn('rc_rej.username', 'radcheck.username')
                  ->where('rc_rej.attribute', 'Auth-Type')
                  ->where('rc_rej.value', 'Reject');
            })
            ->distinct()
            ->count('radcheck.username');

        $active = max(0, $total - $disabled - $expired);

        return compact('total', 'active', 'expired', 'disabled');
    }

    public function availableGroups(): Collection
    {
        $rows = DB::select('
            SELECT groupname FROM radgroupcheck
            UNION
            SELECT groupname FROM radgroupreply
            ORDER BY groupname
        ');

        return collect($rows)->pluck('groupname')->values();
    }

    private static function toRadiusDate(string $date): string
    {
        return Carbon::parse($date)->setTime(23, 59, 59)->format('d M Y H:i:s');
    }
}
