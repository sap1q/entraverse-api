<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return $this->unauthorized('Unauthenticated.');
        }

        if (empty($allowedRoles)) {
            return $next($request);
        }

        if (! in_array($admin->role, $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak.',
                'errors' => [
                    'role' => ['Role tidak diizinkan.'],
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 403);
        }

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => null,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ], 401);
    }
}