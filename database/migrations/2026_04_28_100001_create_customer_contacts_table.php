<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $t) {
            $t->id();

            $t->string('username', 64)->collation('utf8mb4_general_ci')->unique();
            $t->string('name', 100)->nullable();
            $t->string('phone', 20)->index();
            $t->timestamp('reminder_sent_at')->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
