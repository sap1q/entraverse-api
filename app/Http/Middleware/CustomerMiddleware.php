<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\StorefrontUserResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerMiddleware
{
    public function __construct(private readonly StorefrontUserResolver $storefrontUserResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->storefrontUserResolver->resolve($request->user());
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
