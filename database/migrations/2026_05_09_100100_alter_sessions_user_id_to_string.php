<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('TRUNCATE TABLE sessions');
        DB::statement('ALTER TABLE sessions MODIFY COLUMN user_id VARCHAR(64) NULL');
    }

    public function down(): void
    {
        DB::statement('TRUNCATE TABLE sessions');
        DB::statement('ALTER TABLE sessions MODIFY COLUMN user_id BIGINT UNSIGNED NULL');
    }
};
