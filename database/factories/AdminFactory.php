<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => $this->faker->randomElement(['superadmin', 'staff', 'editor']),
            'last_login_at' => null,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => [
            'role' => 'superadmin',
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn (): array => [
            'role' => 'staff',
        ]);
    }

    public function editor(): static
    {
        return $this->state(fn (): array => [
            'role' => 'editor',
        ]);
    }
}
