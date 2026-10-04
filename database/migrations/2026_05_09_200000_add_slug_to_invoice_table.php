<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->string('slug', 30)->nullable()->unique()->after('id');
        });

        Invoice::whereNull('slug')->orderBy('id')->each(function (Invoice $inv) {
            $inv->slug = Invoice::generateSlug($inv->created_at);
            $inv->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
