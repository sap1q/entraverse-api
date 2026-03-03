<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Models\Admin;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegisterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();

        $canBootstrap = ! Admin::query()->exists();
        if (! $canBootstrap && (! $actor || $actor->role !== 'superadmin')) {
            return $this->error('Hanya super admin yang bisa mendaftarkan admin baru.', 403);
        }

        try {
            $result = $this->authService->register($request->validated());

            return $this->created(
                new AuthResource($result['token'], $result['admin']),
                'Admin berhasil didaftarkan.'
            );
        } catch (Throwable $exception) {
            Log::error('Register failed', ['error' => $exception->getMessage()]);
            return $this->error('Terjadi kesalahan pada server.', 500);
        }
    }
}
