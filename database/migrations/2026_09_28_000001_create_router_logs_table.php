<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $t->dateTime('waktu');
            $t->string('topik', 48);
            $t->string('pengguna', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('kejadian', 12);
            $t->string('pesan', 255);
            $t->char('hash', 40);
            $t->timestamp('created_at')->nullable();

            $t->unique(['router_id', 'hash']);
            $t->index(['router_id', 'waktu']);
            $t->index('waktu');
            $t->index('pengguna');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_logs');
    }
};
