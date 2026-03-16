<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\Province;
use App\Services\RajaOngkirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RajaOngkirRegionController extends Controller
{
    public function __construct(
        private readonly RajaOngkirService $rajaOngkirService
    ) {
    }

    public function provinces(): JsonResponse
    {
        $rows = Province::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Province $province) => [
                'id' => $province->id,
                'name' => $province->name,
            ])
            ->all();

        if (count($rows) === 0) {
            try {
                $fetched = $this->rajaOngkirService->getProvinces();
                if (count($fetched) > 0) {
                    Province::query()->upsert($fetched, ['id'], ['name', 'updated_at']);
                    $rows = Province::query()
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (Province $province) => [
                            'id' => $province->id,
                            'name' => $province->name,
                        ])
                        ->all();
                }
            } catch (RuntimeException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'data' => [],
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province_id' => ['required', 'string', 'max:2'],
        ]);

        $provinceId = str_pad((string) $validated['province_id'], 2, '0', STR_PAD_LEFT);

        $rows = City::query()
            ->where('province_id', $provinceId)
            ->orderBy('name')
            ->get(['id', 'province_id', 'name', 'type', 'postal_code'])
            ->map(fn (City $city) => [
                'id' => $city->id,
                'province_id' => $city->province_id,
                'name' => $city->name,
                'type' => $city->type,
                'zip_code' => $city->postal_code,
                'postal_code' => $city->postal_code,
            ])
            ->all();

        if (count($rows) === 0) {
            try {
                $fetched = $this->rajaOngkirService->getCities($provinceId);
                if (count($fetched) > 0) {
                    City::query()->upsert($fetched, ['id'], ['province_id', 'name', 'type', 'postal_code', 'updated_at']);
                    $rows = City::query()
                        ->where('province_id', $provinceId)
                        ->orderBy('name')
                        ->get(['id', 'province_id', 'name', 'type', 'postal_code'])
                        ->map(fn (City $city) => [
                            'id' => $city->id,
                            'province_id' => $city->province_id,
                            'name' => $city->name,
                            'type' => $city->type,
                            'zip_code' => $city->postal_code,
                            'postal_code' => $city->postal_code,
                        ])
                        ->all();
                }
            } catch (RuntimeException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'data' => [],
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ]);
    }

    public function districts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['required', 'string', 'max:4'],
        ]);

        $cityId = str_pad((string) $validated['city_id'], 4, '0', STR_PAD_LEFT);

        $rows = District::query()
            ->where('city_id', $cityId)
            ->orderBy('name')
            ->get(['id', 'city_id', 'name'])
            ->map(fn (District $district) => [
                'id' => $district->id,
                'city_id' => $district->city_id,
                'name' => $district->name,
            ])
            ->all();

        if (count($rows) === 0) {
            try {
                $fetched = $this->rajaOngkirService->getDistricts($cityId);
                if (count($fetched) > 0) {
                    District::query()->upsert($fetched, ['id'], ['city_id', 'name', 'updated_at']);
                    $rows = District::query()
                        ->where('city_id', $cityId)
                        ->orderBy('name')
                        ->get(['id', 'city_id', 'name'])
                        ->map(fn (District $district) => [
                            'id' => $district->id,
                            'city_id' => $district->city_id,
                            'name' => $district->name,
                        ])
                        ->all();
                }
            } catch (RuntimeException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'data' => [],
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ]);
    }

    public function subdistricts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'district_id' => ['required', 'string', 'max:7'],
        ]);

        $districtId = str_pad((string) $validated['district_id'], 7, '0', STR_PAD_LEFT);

        try {
            $rows = $this->rajaOngkirService->getSubdistricts($districtId);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => [],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ]);
    }

    public function origin(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->rajaOngkirService->getShippingOrigin(),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => null,
            ], 422);
        }
    }
}
