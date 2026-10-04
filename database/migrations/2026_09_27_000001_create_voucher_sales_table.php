<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voucher_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $t->string('router_name', 60)->nullable();
            $t->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $t->char('record_hash', 40);
            $t->string('record_name', 512);
            $t->string('router_item_id', 16)->nullable();

            $t->dateTime('sold_at')->nullable();
            $t->boolean('waktu_ragu')->default(false);

            $t->string('username', 64);
            $t->string('profile', 64)->nullable();
            $t->unsignedInteger('price')->nullable();
            $t->unsignedInteger('sprice')->nullable();
            $t->string('sprice_sumber', 20)->nullable();
            $t->string('validity', 20)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('mac', 17)->nullable();
            $t->string('batch_comment', 64)->nullable();
            $t->string('source', 16)->default('mikhmon');

            $t->timestamp('dihapus_dari_router_at')->nullable();
            $t->timestamps();

            $t->unique(['router_id', 'record_hash']);
            $t->index(['waktu_ragu', 'sold_at']);
            $t->index(['router_id', 'waktu_ragu', 'sold_at']);
            $t->index('username');
        });

        Schema::table('voucher_batches', function (Blueprint $t) {
            $t->string('source', 16)->default('panel')->after('status');
        });

        Schema::table('routers', function (Blueprint $t) {
            $t->string('ros_version', 40)->nullable()->after('wan_interface');
            $t->timestamp('last_sales_pull_at')->nullable()->after('ros_version');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $t) {
            $t->dropColumn(['ros_version', 'last_sales_pull_at']);
        });

        Schema::table('voucher_batches', function (Blueprint $t) {
            $t->dropColumn('source');
        });

        Schema::dropIfExists('voucher_sales');
    }
};
