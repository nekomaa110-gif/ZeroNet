<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Router extends Model
{
    protected $fillable = [
        'slug', 'name', 'host', 'port', 'username', 'password',
        'timeout', 'wan_interface', 'sort_order', 'template_cetak',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password'   => 'encrypted',
        'port'       => 'integer',
        'timeout'    => 'integer',
        'sort_order' => 'integer',
        'last_sales_pull_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function toConfig(): array
    {
        try {
            $pass = (string) $this->password;
        } catch (\Throwable) {
            $pass = '';
        }

        return [
            'name'          => $this->name,
            'host'          => $this->host,
            'port'          => $this->port,
            'user'          => $this->username,
            'pass'          => $pass,
            'timeout'       => $this->timeout,
            'wan_interface' => $this->wan_interface,
        ];
    }

    public static function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'router';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
