<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }

        Schema::create('deleted_vouchers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voucher_id')->unique();
            $t->unsignedBigInteger('batch_id')->nullable()->index();
            $t->string('batch_code', 64)->nullable();
            $t->unsignedBigInteger('router_id')->nullable();
            $t->string('router_name', 60)->nullable();
            $t->unsignedInteger('nomor')->nullable();
            $t->string('username', 64)->index();
            $t->string('password', 64);
            $t->string('profile', 64)->nullable();
            $t->string('comment', 64)->nullable();
            $t->unsignedInteger('price')->nullable();
            $t->string('status', 10);
            $t->string('sync_status', 8)->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->timestamp('printed_at')->nullable();
            $t->unsignedInteger('uptime_used')->default(0);
            $t->string('hasil_router', 20);
            $t->string('cara', 10);
            $t->string('sumber', 10);
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->dateTime('deleted_at')->index();
            $t->timestamps();

            $t->index(['router_id', 'username']);
            $t->index(['batch_id', 'nomor']);
        });

        Schema::table('vouchers', function (Blueprint $t) {
            $t->unsignedInteger('nomor')->nullable()->after('batch_id');
            $t->index(['batch_id', 'nomor']);
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropIndex(['batch_id', 'nomor']);
            $t->dropColumn('nomor');
        });

        Schema::dropIfExists('deleted_vouchers');
    }
};
