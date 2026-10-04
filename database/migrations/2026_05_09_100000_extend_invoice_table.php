<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->dateTime('paid_at')->nullable()->after('due_date');
            $table->dateTime('confirmed_at')->nullable()->after('paid_at');
            $table->unsignedBigInteger('confirmed_by')->nullable()->after('confirmed_at');
            $table->string('payment_proof', 255)->nullable()->after('confirmed_by');
            $table->string('bank_to', 50)->nullable()->after('payment_proof');
            $table->dateTime('extended_to')->nullable()->after('bank_to');
            $table->text('notes')->nullable()->after('extended_to');
            $table->timestamp('updated_at')->nullable()->after('created_at');

            $table->index(['username', 'status'], 'idx_invoice_user_status');
            $table->index('status', 'idx_invoice_status');
        });

        DB::statement("ALTER TABLE invoice MODIFY COLUMN status ENUM('draft','unpaid','pending_confirmation','paid','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoice MODIFY COLUMN status ENUM('unpaid','paid') DEFAULT 'unpaid'");

        Schema::table('invoice', function (Blueprint $table) {
            $table->dropIndex('idx_invoice_user_status');
            $table->dropIndex('idx_invoice_status');
            $table->dropColumn([
                'paid_at', 'confirmed_at', 'confirmed_by',
                'payment_proof', 'bank_to', 'extended_to',
                'notes', 'updated_at',
            ]);
        });
    }
};
