<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_samples', function (Blueprint $table) {
            $table->index(['router_slug', 'interface', 'sampled_at'], 'traffic_samples_router_iface_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_samples', function (Blueprint $table) {
            $table->dropIndex('traffic_samples_router_iface_time_index');
        });
    }
};
