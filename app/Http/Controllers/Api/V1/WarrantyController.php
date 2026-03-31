<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWarrantyRequest;
use App\Http\Requests\UpdateWarrantyRequest;
use App\Http\Requests\WarrantyLookupRequest;
use App\Models\Product;
use App\Models\Warranty;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WarrantyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));

        $warranties = Warranty::query()
            ->with('product')
            ->search($request->query('search'))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar garansi berhasil diambil.',
            'data' => collect($warranties->items())->map(
                fn (Warranty $warranty): array => $this->transformWarranty($warranty)
            )->values()->all(),
            'pagination' => [
                'current_page' => $warranties->currentPage(),
                'per_page' => $warranties->perPage(),
                'total' => $warranties->total(),
                'last_page' => $warranties->lastPage(),
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function store(StoreWarrantyRequest $request): JsonResponse
    {
        try {
            $warranty = Warranty::query()->create($request->validated());
            $warranty->load('product');

            return response()->json([
                'success' => true,
                'message' => 'Data garansi berhasil ditambahkan.',
                'data' => $this->transformWarranty($warranty),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 201);
        } catch (QueryException $exception) {
            return $this->handleDuplicateRecord($exception);
        }
    }

    public function show(string $warrantyId): JsonResponse
    {
        $warranty = Warranty::query()
            ->with('product')
            ->findOrFail($warrantyId);

        return response()->json([
            'success' => true,
            'message' => 'Detail garansi berhasil diambil.',
            'data' => $this->transformWarranty($warranty),
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function update(UpdateWarrantyRequest $request, string $warrantyId): JsonResponse
    {
        $warranty = Warranty::query()->findOrFail($warrantyId);

        try {
            $warranty->fill($request->validated());
            $warranty->save();
            $warranty->load('product');

            return response()->json([
                'success' => true,
                'message' => 'Data garansi berhasil diperbarui.',
                'data' => $this->transformWarranty($warranty),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ]);
        } catch (QueryException $exception) {
            return $this->handleDuplicateRecord($exception);
        }
    }

    public function destroy(string $warrantyId): JsonResponse
    {
        $warranty = Warranty::query()->findOrFail($warrantyId);
        $warranty->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data garansi berhasil dihapus.',
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function lookup(WarrantyLookupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $warranty = Warranty::query()
            ->with('product')
            ->where('invoice_number', (string) $validated['invoice_number'])
            ->where('serial_number', (string) $validated['serial_number'])
            ->first();

        if (! $warranty) {
            return response()->json([
                'success' => false,
                'message' => 'Data garansi tidak ditemukan untuk kombinasi invoice dan serial number tersebut.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data garansi berhasil ditemukan.',
            'data' => $this->transformWarranty($warranty),
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    private function transformWarranty(Warranty $warranty): array
    {
        $product = $warranty->product;
        $productImage = $product instanceof Product ? $this->resolveProductImage($product) : null;
        $today = now()->startOfDay();
        $startDate = $warranty->start_date?->copy()?->startOfDay();
        $endDate = $warranty->end_date?->copy()?->startOfDay();

        $status = 'inactive';
        if ($startDate && $endDate) {
            if ($today->lt($startDate)) {
                $status = 'upcoming';
            } elseif ($today->lte($endDate)) {
                $status = 'active';
            } else {
                $status = 'expired';
            }
        }

        return [
            'id' => (string) $warranty->id,
            'customer_name' => (string) $warranty->customer_name,
            'phone' => $warranty->phone,
            'address' => $warranty->address,
            'invoice_number' => (string) $warranty->invoice_number,
            'serial_number' => (string) $warranty->serial_number,
            'start_date' => optional($warranty->start_date)?->toDateString(),
            'end_date' => optional($warranty->end_date)?->toDateString(),
            'status' => $status,
            'product' => $product ? [
                'id' => (string) $product->id,
                'name' => (string) $product->name,
                'spu' => (string) ($product->spu ?? ''),
                'main_image' => $productImage,
            ] : null,
            'product_id' => $product ? (string) $product->id : (string) $warranty->product_id,
            'created_at' => optional($warranty->created_at)?->toISOString(),
            'updated_at' => optional($warranty->updated_at)?->toISOString(),
        ];
    }

    private function resolveProductImage(Product $product): ?string
    {
        $photos = is_array($product->photos) ? $product->photos : [];

        foreach ($photos as $photo) {
            if (is_array($photo) && ($photo['is_primary'] ?? false) === true) {
                $url = trim((string) ($photo['url'] ?? ''));
                if ($url !== '') {
                    return $this->normalizeAssetUrl($url);
                }
            }
        }

        foreach ($photos as $photo) {
            if (is_string($photo) && trim($photo) !== '') {
                return $this->normalizeAssetUrl($photo);
            }

            if (is_array($photo)) {
                $url = trim((string) ($photo['url'] ?? ''));
                if ($url !== '') {
                    return $this->normalizeAssetUrl($url);
                }
            }
        }

        return null;
    }

    private function normalizeAssetUrl(string $value): string
    {
        $trimmed = trim($value);
        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return $trimmed;
        }

        if (Str::startsWith($trimmed, 'storage/')) {
            return url('/' . ltrim($trimmed, '/'));
        }

        if (Str::startsWith($trimmed, '/')) {
            return url($trimmed);
        }

        return url('/storage/products/' . ltrim($trimmed, '/'));
    }

    private function handleDuplicateRecord(QueryException $exception): JsonResponse
    {
        $message = strtolower((string) $exception->getMessage());
        if (! str_contains($message, 'warranties_invoice_serial_unique')) {
            throw $exception;
        }

        return response()->json([
            'success' => false,
            'message' => 'Kombinasi invoice dan serial number sudah terdaftar di data garansi.',
            'errors' => [
                'invoice_number' => ['Kombinasi invoice dan serial number sudah digunakan.'],
                'serial_number' => ['Kombinasi invoice dan serial number sudah digunakan.'],
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ], 422);
    }
}
