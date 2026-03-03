<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Admin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthService
{
    /**
     * @param array<string, mixed> $credentials
     * @return array{token: string, admin: Admin}
     */
    public function login(array $credentials): array
    {
        /** @var Admin|null $admin */
        $admin = Admin::query()->where('email', $credentials['email'])->first();

        if (! $admin || ! Hash::check((string) $credentials['password'], $admin->password)) {
            throw new AuthenticationException('Kredensial tidak valid.');
        }

        $admin->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $admin->createToken('admin-panel')->plainTextToken;

        Log::info('Admin login success', ['admin_id' => (string) $admin->id]);

        return [
            'token' => $token,
            'admin' => $admin,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, admin: Admin}
     */
    public function register(array $payload): array
    {
        $admin = Admin::query()->create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => $payload['password'],
            'role' => $payload['role'] ?? 'staff',
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        Log::info('Admin register success', ['admin_id' => (string) $admin->id]);

        return [
            'token' => $token,
            'admin' => $admin,
        ];
    }

    public function logout(Admin $admin): void
    {
        $admin->currentAccessToken()?->delete();
    }

    public function profile(Admin $admin): Admin
    {
        $fresh = $admin->fresh();

        if (! $fresh) {
            throw new ModelNotFoundException('Admin tidak ditemukan.');
        }

        return $fresh;
    }
}