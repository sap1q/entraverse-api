<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoginController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());

            return $this->success(
                new AuthResource($result['token'], $result['admin']),
                'Login berhasil.'
            );
        } catch (AuthenticationException $exception) {
            return $this->error('Email atau password tidak valid.', 401);
        } catch (Throwable $exception) {
            Log::error('Login failed', ['error' => $exception->getMessage()]);
            return $this->error('Terjadi kesalahan pada server.', 500);
        }
    }
}