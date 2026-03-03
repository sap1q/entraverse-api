<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message ?? 'Success',
            'data' => $data,
            'meta' => $this->meta(),
        ], $code);
    }

    protected function error(?string $message = null, int $code = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message ?? 'Request failed',
            'errors' => $errors,
            'meta' => $this->meta(),
        ], $code);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->success($data, $message ?? 'Created', 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    private function meta(): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'version' => 'v1',
        ];
    }
}