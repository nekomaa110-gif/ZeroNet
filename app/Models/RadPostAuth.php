<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RadPostAuth extends Model
{
    protected $table = 'radpostauth';
    public $timestamps = false;

    protected $casts = [
        'authdate' => 'datetime',
    ];

    public function isSuccess(): bool
    {
        return str_contains(strtolower($this->reply), 'accept');
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }
        return $query->where('username', 'like', "%{$search}%");
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'success' => $query->where('reply', 'like', '%Accept%'),
            'failed'  => $query->where('reply', 'not like', '%Accept%'),
            default   => $query,
        };
    }

    public function scopeByDateFrom(Builder $query, ?string $date): Builder
    {
        if (!$date) {
            return $query;
        }
        return $query->where('authdate', '>=', $date . ' 00:00:00');
    }

    public function scopeByDateTo(Builder $query, ?string $date): Builder
    {
        if (!$date) {
            return $query;
        }
        return $query->where('authdate', '<=', $date . ' 23:59:59');
    }

    public function scopeCleaned(Builder $query): Builder
    {
        return $query
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('radpostauth as p2')
                  ->whereColumn('p2.username', 'radpostauth.username')
                  ->whereColumn('p2.reply', 'radpostauth.reply')
                  ->whereColumn('p2.id', '<', 'radpostauth.id')
                  ->whereRaw('TIMESTAMPDIFF(SECOND, p2.authdate, radpostauth.authdate) < 5');
            })
            ->where(function ($q) {
                $q->where('radpostauth.reply', 'like', '%Accept%')
                  ->orWhereNotExists(function ($q2) {
                      $q2->select(DB::raw(1))
                         ->from('radpostauth as p3')
                         ->whereColumn('p3.username', 'radpostauth.username')
                         ->where('p3.reply', 'like', '%Accept%')
                         ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, p3.authdate, radpostauth.authdate)) <= 300');
                  });
            });
    }
}
