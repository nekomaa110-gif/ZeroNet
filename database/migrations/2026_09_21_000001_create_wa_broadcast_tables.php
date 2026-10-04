<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('target', 20);
            $table->text('message');
            $table->unsignedInteger('total')->default(0);

            $table->string('status', 12)->default('running');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('wa_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('wa_broadcasts')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('username', 100);
            $table->string('name', 100)->nullable();
            $table->string('phone', 20);

            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_broadcast_recipients');
        Schema::dropIfExists('wa_broadcasts');
    }
};
