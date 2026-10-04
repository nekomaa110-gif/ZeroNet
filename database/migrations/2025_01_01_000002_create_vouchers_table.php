<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('username', 64)->nullable();
            $table->string('profile', 64)->nullable();
            $table->enum('status', ['unused', 'used', 'expired'])->default('unused');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('profile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
