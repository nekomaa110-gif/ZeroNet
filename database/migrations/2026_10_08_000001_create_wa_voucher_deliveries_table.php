<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_voucher_deliveries', function (Blueprint $t) {
            $t->id();
            $t->string('username', 64)->collation('utf8mb4_general_ci')->index();
            $t->string('phone', 20)->nullable();
            $t->string('status', 20)->index();
            $t->string('reason', 255)->nullable();
            $t->string('message_id', 100)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_voucher_deliveries');
    }
};
