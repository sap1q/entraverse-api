<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@entraverse.com'],
            [
                'name' => 'Entraverse',
                'password' => Hash::make('entraver123'),
                'role' => 'staff',
                'last_login_at' => null,
            ]
        );
    }
}
