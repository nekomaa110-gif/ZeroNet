<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE customer_remember_tokens '
            . 'MODIFY username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'
        );

        Schema::table('customer_remember_tokens', function (Blueprint $t) {
            $t->string('password_fingerprint', 64)->nullable()->after('token');
        });

        foreach (DB::table('customer_remember_tokens')->get() as $row) {
            $password = DB::table('radcheck')
                ->whereRaw('BINARY username = ?', [$row->username])
                ->where('attribute', 'Cleartext-Password')
                ->value('value');

            DB::table('customer_remember_tokens')
                ->where('id', $row->id)
                ->update([
                    'password_fingerprint' => $password === null
                        ? null
                        : hash('sha256', (string) $password),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('customer_remember_tokens', function (Blueprint $t) {
            $t->dropColumn('password_fingerprint');
        });

        DB::statement(
            'ALTER TABLE customer_remember_tokens '
            . 'MODIFY username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL'
        );
    }
};
