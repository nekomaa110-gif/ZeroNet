<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $t) {
            $t->id();

            $t->string('slug', 64)->unique();
            $t->string('name', 60);
            $t->string('host', 100);
            $t->unsignedSmallInteger('port')->default(8728);
            $t->string('username', 64);
            $t->text('password');
            $t->unsignedTinyInteger('timeout')->default(5);
            $t->string('wan_interface', 32)->default('ether1');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        $rows  = [];
        $order = 0;
        foreach ((array) config('mikrotik.routers', []) as $slug => $cfg) {
            $rows[] = [
                'slug'          => $slug,
                'name'          => $cfg['name'] ?? $slug,
                'host'          => $cfg['host'] ?? '',
                'port'          => (int) ($cfg['port'] ?? 8728),
                'username'      => $cfg['user'] ?? 'admin',
                'password'      => Crypt::encryptString((string) ($cfg['pass'] ?? '')),
                'timeout'       => (int) ($cfg['timeout'] ?? 5),
                'wan_interface' => 'ether1',
                'sort_order'    => $order += 10,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        if ($rows) {
            DB::table('routers')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
