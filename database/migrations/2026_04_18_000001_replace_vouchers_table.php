<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('vouchers');

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('type', 4);
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->unsignedInteger('session_seconds')->nullable();
            $table->unsignedSmallInteger('calendar_hours');
            $table->enum('status', ['ready', 'active', 'expired', 'disabled'])->default('ready');
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
