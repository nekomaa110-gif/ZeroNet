<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l2tp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('secret', 64);
            $table->string('remote_ip', 15)->unique();
            $table->string('note', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('l2tp_accounts', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l2tp_accounts');
    }
};
