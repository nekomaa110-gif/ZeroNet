<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUser('admin', config('seeder.admin', []));
        $this->seedUser('operator', config('seeder.operator', []));
    }

    private function seedUser(string $role, array $data): void
    {
        foreach (['name', 'username', 'email', 'password'] as $key) {
            if (empty($data[$key] ?? null)) {
                $this->command?->warn("AdminSeeder: lewati role={$role} (config seeder.{$role}.{$key} kosong).");
                return;
            }
        }

        User::updateOrCreate(
            ['email' => $data['email']],
            [
                'name'      => $data['name'],
                'username'  => $data['username'],
                'email'     => $data['email'],
                'password'  => Hash::make($data['password']),
                'role'      => $role,
                'is_active' => true,
            ]
        );
    }
}
