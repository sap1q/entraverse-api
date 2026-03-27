<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingCostRequest;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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
            /** @var User $user */
            $user = $request->user();
            $result = $this->checkoutService->estimateShippingCost(
                user: $user,
                courier: (string) $validated['courier'],
                addressId: isset($validated['address_id']) ? (string) $validated['address_id'] : null,
                destinationCityId: isset($validated['city_id']) ? (string) $validated['city_id'] : null,
                destinationDistrictId: isset($validated['district_id']) ? (string) $validated['district_id'] : null,
                weight: isset($validated['weight']) ? (int) $validated['weight'] : null,
                itemsPayload: is_array($validated['items'] ?? null) ? $validated['items'] : []
            );

            return response()->json([
                'success' => true,
                'message' => 'Estimasi ongkir berhasil diambil.',
                'data' => $result,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi estimasi ongkir gagal.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
