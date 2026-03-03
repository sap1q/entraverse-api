<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@example.com';

        $admin = Admin::query()->where('email', $email)->first();

        if ($admin) {
            $admin->forceFill([
                'name' => 'Entraverse Admin',
                'role' => 'superadmin',
                'password' => Hash::make('password123'),
                'last_login_at' => null,
            ])->save();

            $this->command?->info('Admin updated: '.$email);
            return;
        }

        Admin::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Entraverse Admin',
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'superadmin',
            'last_login_at' => null,
        ]);

        $this->command?->info('Admin created: '.$email);
    }
}