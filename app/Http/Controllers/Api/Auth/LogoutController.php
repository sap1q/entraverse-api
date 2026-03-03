<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class LogoutController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService)
    {
    }

    public function logout(): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = request()->user();

        if (! $admin) {
            return $this->error('Unauthenticated.', 401);
        }

        $this->authService->logout($admin);

        return $this->success(null, 'Logout berhasil.');
    }
}