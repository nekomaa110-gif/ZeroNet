<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('load_balance_samples', 'traffic_samples');
    }

    public function down(): void
    {
        Schema::rename('traffic_samples', 'load_balance_samples');
    }
};
