<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable()->after('speed_label');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('price');

            $table->string('public_description', 255)->nullable()->after('validity_days');
            $table->boolean('is_public')->default(false)->after('public_description');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['price', 'validity_days', 'public_description', 'is_public', 'sort_order']);
        });
    }
};
