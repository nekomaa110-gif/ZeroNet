<?php

use App\Services\MikrotikService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $taken = [];

        foreach (DB::table('routers')->orderBy('id')->get(['id', 'name', 'slug']) as $row) {
            $base = Str::slug($row->name) ?: 'router';
            $slug = $base;

            for ($i = 2; in_array($slug, $taken, true); $i++) {
                $slug = "{$base}-{$i}";
            }

            $taken[] = $slug;

            if ($slug !== $row->slug) {
                DB::table('routers')->where('id', $row->id)->update(['slug' => $slug]);
            }
        }

        MikrotikService::forgetRouterCache();
    }

    public function down(): void
    {
    }
};
