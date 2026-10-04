<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_remember_tokens', function (Blueprint $t) {
            $t->id();

            $t->string('username', 64)->collation('utf8mb4_general_ci')->unique();
            $t->string('token', 100);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_remember_tokens');
    }
};
