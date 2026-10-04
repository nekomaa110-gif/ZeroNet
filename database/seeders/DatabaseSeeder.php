<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! config('seeder.allow_production')) {
            $this->command?->warn('DatabaseSeeder: AdminSeeder dilewati di production. Set ADMIN_SEED_ALLOW_PRODUCTION=true di .env, jalankan config:cache, lalu seed.');
            return;
        }

        $this->call([
            AdminSeeder::class,
        ]);
    }
}
