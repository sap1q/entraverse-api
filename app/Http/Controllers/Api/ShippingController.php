<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingCostRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ShippingController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function cost(ShippingCostRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->checkoutService->estimateShippingCost(
                destinationCityId: (string) $validated['city_id'],
                weight: (int) $validated['weight'],
                courier: (string) $validated['courier']
            );

            return response()->json([
                'success' => true,
                'message' => 'Estimasi ongkir berhasil diambil.',
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}

