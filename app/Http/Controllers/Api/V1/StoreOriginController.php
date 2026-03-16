<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertStoreOriginRequest;
use App\Models\StoreOrigin;
use App\Services\RajaOngkirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreOriginController extends Controller
{
    public function __construct(
        private readonly RajaOngkirService $rajaOngkirService
    ) {
    }

    public function show(): JsonResponse
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

    public function upsert(UpsertStoreOriginRequest $request): JsonResponse
    {
        $payload = $request->validated();

        DB::transaction(function () use ($payload): void {
            $record = StoreOrigin::query()
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->first();

            if (! $record) {
                $record = StoreOrigin::query()
                    ->orderByDesc('updated_at')
                    ->first();
            }

            $normalized = [
                'label' => (string) $payload['label'],
                'recipient_name' => (string) $payload['recipient_name'],
                'recipient_phone' => (string) $payload['recipient_phone'],
                'province_id' => (string) $payload['province_id'],
                'city_id' => (string) $payload['city_id'],
                'district_id' => (string) $payload['district_id'],
                'subdistrict' => $this->toNullable($payload['subdistrict'] ?? null),
                'address_detail' => (string) $payload['address_detail'],
                'zip_code' => $this->toNullable($payload['zip_code'] ?? null),
                'location_note' => $this->toNullable($payload['location_note'] ?? null),
                'is_active' => true,
            ];

            if ($record) {
                $record->fill($normalized);
                $record->save();

                StoreOrigin::query()
                    ->whereKeyNot($record->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                return;
            }

            StoreOrigin::query()->create($normalized);
        });

        return response()->json([
            'success' => true,
            'message' => 'Asal lokasi toko berhasil disimpan.',
            'data' => $this->rajaOngkirService->getShippingOrigin(),
        ]);
    }

    private function toNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
