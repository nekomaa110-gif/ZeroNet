<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');

        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->string('router_name', 60)->nullable();

            $table->string('code', 64)->unique();
            $table->string('label', 60)->nullable();

            $table->string('profile', 64);
            $table->string('server', 64)->nullable();
            $table->unsignedInteger('quantity');

            $table->string('mode', 4)->default('vc');
            $table->string('charset', 8)->default('lower');
            $table->unsignedTinyInteger('code_length')->default(4);
            $table->unsignedTinyInteger('password_length')->nullable();
            $table->string('prefix', 20)->nullable();
            $table->string('suffix', 20)->nullable();

            $table->string('limit_uptime', 20)->nullable();
            $table->unsignedBigInteger('limit_bytes')->nullable();
            $table->string('validity', 20)->nullable();
            $table->unsignedInteger('price')->nullable();

            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('synced_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamp('printed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('voucher_batches')->cascadeOnDelete();
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();

            $table->string('username', 64);
            $table->string('password', 64);
            $table->string('profile', 64);
            $table->string('server', 64)->nullable();
            $table->string('comment', 64)->nullable();

            $table->string('limit_uptime', 20)->nullable();
            $table->unsignedBigInteger('limit_bytes')->nullable();
            $table->unsignedInteger('price')->nullable();

            $table->string('status', 10)->default('ready');

            $table->string('sync_status', 8)->default('pending');
            $table->string('sync_error', 255)->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamp('printed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedInteger('uptime_used')->default(0);
            $table->timestamp('checked_at')->nullable();

            $table->timestamps();

            $table->unique(['router_id', 'username']);
            $table->index('username');
            $table->index('status');
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');
    }
};
