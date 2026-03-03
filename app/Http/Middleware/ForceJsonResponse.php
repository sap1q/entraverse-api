<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            return $response;
        }

        if ($response->getStatusCode() >= 400) {
            return response()->json([
                'success' => false,
                'message' => Response::$statusTexts[$response->getStatusCode()] ?? 'Request failed',
                'errors' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], $response->getStatusCode());
        }

        return $response;
    }
}
