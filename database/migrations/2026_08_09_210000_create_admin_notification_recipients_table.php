<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);

            $table->string('phone', 20)->unique();

            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        $existing = (string) config('services.billing.admin_notif_wa', '');

        if ($existing !== '') {
            DB::table('admin_notification_recipients')->insert([
                'name'       => 'Admin',
                'phone'      => \App\Services\WhatsAppService::normalizePhone($existing),
                'events'     => json_encode(array_keys(\App\Models\AdminNotificationRecipient::EVENTS)),
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_recipients');
    }
};
