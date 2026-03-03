<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\AdminResource;
use App\Models\Admin;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService)
    {
    }

    public function profile(): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = request()->user();

        if (! $admin) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $this->authService->profile($admin);

        return $this->success(new AdminResource($profile), 'Profile admin berhasil diambil.');
    }
}