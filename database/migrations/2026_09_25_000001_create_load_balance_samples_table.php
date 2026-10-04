<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_balance_samples', function (Blueprint $table) {
            $table->id();
            $table->string('router_slug', 64);
            $table->string('interface', 64);
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->unsignedInteger('uptime');
            $table->timestamp('sampled_at');

            $table->index(['router_slug', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_balance_samples');
    }
};
