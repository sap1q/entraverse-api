<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StorefrontUserResolver
{
    public function resolve(mixed $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        if (! $actor instanceof Admin) {
            return null;
        }

        $email = trim((string) $actor->email);
        if ($email === '') {
            return null;
        }

        $name = trim((string) $actor->name);

        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : 'Admin ' . (string) $actor->id,
                'password' => Hash::make(Str::random(40)),
            ]
        );
    }
}
