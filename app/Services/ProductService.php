<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    private const MAX_PRODUCT_PHOTOS = 5;
    private const DEFAULT_WAREHOUSE = 'Gudang Utama';

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 12), 100));
        $driver = DB::connection()->getDriverName();

        return Product::query()
            ->when($filters['category_id'] ?? null, fn (Builder $query, string $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['brand'] ?? null, fn (Builder $query, string $brand) => $query->where('brand', $brand))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($search !== '', function (Builder $query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(fn (Builder $q) => $q
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('brand', 'ilike', "%{$search}%")
                        ->orWhere('spu', 'ilike', "%{$search}%"));
                    return;
                }

                $keyword = '%' . strtolower($search) . '%';
                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(brand) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(spu) LIKE ?', [$keyword]));
            })
            ->latest()
            ->paginate($perPage)
            ->appends($filters);
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function store(array $validated, array $images = []): Product
    {
        $payload = $this->buildPayload($validated, null, $images);
        return Product::query()->create($payload);
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function update(Product $product, array $validated, array $images = []): Product
    {
        $payload = $this->buildPayload($validated, $product, $images);
        $product->update($payload);
        return $product->refresh();
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedImages
     */
    private function buildPayload(array $validated, ?Product $product, array $uploadedImages): array
    {
        $existingInventory = is_array($product?->inventory) ? $product->inventory : [];
        $requestedInventory = is_array($validated['inventory'] ?? null) ? $validated['inventory'] : [];
        $inventory = array_merge($existingInventory, $requestedInventory);

        if (array_key_exists('price', $validated)) {
            $inventory['price'] = (float) $validated['price'];
        }
        if (array_key_exists('weight', $validated)) {
            $inventory['weight'] = (int) $validated['weight'];
        }

        $variantPricing = $this->normalizeVariantPricing(
            $validated['variant_pricing'] ?? ($product?->variant_pricing ?? [])
        );

        $calculatedStock = $this->calculateTotalStock(
            $variantPricing,
            $validated['stock'] ?? null,
            $inventory['total_stock'] ?? null,
            $product?->stock
        );
        $inventory['total_stock'] = $calculatedStock;
        $categoryId = (string) ($validated['category_id'] ?? $product?->category_id ?? '');
        $categoryName = $this->cleanText((string) ($validated['category'] ?? $product?->category ?? ''));

        if ($categoryName === '' && $categoryId !== '') {
            $categoryName = (string) (Category::query()->where('id', $categoryId)->value('name') ?? '');
        }

        return [
            'name' => $this->cleanText((string) ($validated['name'] ?? $product?->name ?? '')),
            'category' => $categoryName,
            'category_id' => $categoryId !== '' ? $categoryId : null,
            'brand' => $this->cleanText((string) ($validated['brand'] ?? $product?->brand ?? '')),
            'description' => $this->cleanDescription((string) ($validated['description'] ?? $product?->description ?? '')),
            'trade_in' => (bool) ($validated['trade_in'] ?? $product?->trade_in ?? false),
            'inventory' => $inventory,
            'variants' => $this->normalizeArray($validated['variants'] ?? ($product?->variants ?? [])),
            'variant_pricing' => $variantPricing,
            'photos' => $this->resolvePhotos($validated['photos'] ?? null, $uploadedImages, $product?->photos ?? []),
            'spu' => $validated['spu'] ?? $product?->spu ?? $this->generateSpu((string) ($validated['brand'] ?? $product?->brand ?? 'ENTRAVERSE')),
            'product_status' => $validated['product_status'] ?? $product?->product_status ?? 'active',
            'stock' => $calculatedStock,
        ];
    }

    private function normalizeVariantPricing(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $stock = max(0, (int) ($item['stock'] ?? 0));
            $warehouse = trim((string) ($item['warehouse'] ?? ''));
            $warehouse = $warehouse !== '' ? $warehouse : self::DEFAULT_WAREHOUSE;
            $warehouseStock = $this->normalizeWarehouseStock(
                $item['warehouse_stock'] ?? null,
                $warehouse,
                $stock
            );

            $item['warehouse_stock'] = $warehouseStock;
            $item['warehouse'] = (string) (array_key_first($warehouseStock) ?? $warehouse);
            $item['stock'] = (int) array_sum($warehouseStock);
            $item['purchase_price'] = (float) ($item['purchase_price'] ?? 0);
            $item['purchase_price_idr'] = (float) ($item['purchase_price_idr'] ?? 0);

            $normalized[] = $this->normalizeArray($item);
        }

        return $normalized;
    }

    private function normalizeWarehouseStock(mixed $warehouseStock, string $fallbackWarehouse, int $fallbackStock): array
    {
        $normalized = [];

        if (is_array($warehouseStock)) {
            foreach ($warehouseStock as $warehouse => $qty) {
                $warehouseName = trim(is_string($warehouse) ? $warehouse : '');
                if ($warehouseName === '') {
                    continue;
                }

                $normalized[$warehouseName] = max(0, (int) $qty);
            }
        }

        if ($normalized === []) {
            $normalized[$fallbackWarehouse] = max(0, $fallbackStock);
        }

        return $normalized;
    }

    private function calculateTotalStock(array $variantPricing, mixed $fallbackStock, mixed $inventoryStock, mixed $currentStock): int
    {
        $fromVariants = (int) collect($variantPricing)->sum(fn (array $item) => (int) ($item['stock'] ?? 0));
        if ($fromVariants > 0 || ($variantPricing !== [] && $fromVariants === 0)) {
            return $fromVariants;
        }

        if ($fallbackStock !== null) {
            return max(0, (int) $fallbackStock);
        }

        if ($inventoryStock !== null) {
            return max(0, (int) $inventoryStock);
        }

        return max(0, (int) ($currentStock ?? 0));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedImages
     * @param  array<int|string, mixed>  $existingPhotos
     * @return array<int, array<string, mixed>>
     */
    private function resolvePhotos(mixed $photos, array $uploadedImages, array $existingPhotos): array
    {
        $uploadMarkerPrefix = '__UPLOAD__:';

        $isValidUrl = static function (string $value): bool {
            $trimmed = trim($value);
            if ($trimmed === '') return false;
            return !str_starts_with($trimmed, 'blob:') && !str_starts_with($trimmed, 'data:');
        };

        $normalizePhotoItem = static function (mixed $photo) use ($isValidUrl): ?array {
            if (is_string($photo)) {
                $trimmed = trim($photo);
                if (! $isValidUrl($trimmed)) return null;
                return [
                    'url' => $trimmed,
                    'alt' => null,
                    'is_primary' => false,
                ];
            }

            if (is_array($photo)) {
                $url = trim((string) ($photo['url'] ?? ''));
                if (! $isValidUrl($url)) return null;
                return [
                    'url' => $url,
                    'alt' => is_string($photo['alt'] ?? null) ? $photo['alt'] : null,
                    'is_primary' => false,
                ];
            }

            return null;
        };

        $sanitizePhotos = function (array $items): array {
            return array_values(array_filter($items, function (mixed $photo): bool {
                if (is_string($photo)) {
                    $trimmed = trim($photo);
                    if ($trimmed === '') return false;
                    return !str_starts_with($trimmed, 'blob:') && !str_starts_with($trimmed, 'data:');
                }

                if (is_array($photo)) {
                    $url = trim((string) ($photo['url'] ?? ''));
                    if ($url === '') return false;
                    return !str_starts_with($url, 'blob:') && !str_starts_with($url, 'data:');
                }

                return false;
            }));
        };

        $mappedUploads = [];
        foreach ($uploadedImages as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('products', 'public');
            $mappedUploads[] = [
                'url' => '/storage/' . ltrim($path, '/'),
                'alt' => null,
                'is_primary' => false,
            ];
        }

        // New synchronized flow: `photos` can contain ordered placeholders "__UPLOAD__:<n>".
        if (is_array($photos)) {
            $orderedPhotos = [];
            $uploadCursor = 0;

            foreach ($this->normalizeArray($photos) as $photo) {
                if (is_string($photo) && str_starts_with($photo, $uploadMarkerPrefix)) {
                    if (isset($mappedUploads[$uploadCursor])) {
                        $orderedPhotos[] = $mappedUploads[$uploadCursor];
                        $uploadCursor++;
                    }
                    continue;
                }

                $normalized = $normalizePhotoItem($photo);
                if ($normalized !== null) {
                    $orderedPhotos[] = $normalized;
                }
            }

            // Backward compatibility: append remaining uploaded files if marker not provided for all.
            while (isset($mappedUploads[$uploadCursor])) {
                $orderedPhotos[] = $mappedUploads[$uploadCursor];
                $uploadCursor++;
            }

            return $this->finalizePhotos($orderedPhotos);
        }

        $basePhotos = $sanitizePhotos($this->normalizeArray($existingPhotos));
        $normalizedBase = array_values(array_filter(array_map($normalizePhotoItem, $basePhotos)));
        $merged = array_values(array_merge($normalizedBase, $mappedUploads));

        return $this->finalizePhotos($merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function finalizePhotos(array $items): array
    {
        $result = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '' || str_starts_with($url, 'blob:') || str_starts_with($url, 'data:')) {
                continue;
            }

            $dedupeKey = strtolower($url);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $result[] = [
                'url' => $url,
                'alt' => is_string($item['alt'] ?? null) ? $item['alt'] : null,
                'is_primary' => false,
            ];

            if (count($result) >= self::MAX_PRODUCT_PHOTOS) {
                break;
            }
        }

        return array_values(array_map(
            fn (array $photo, int $index) => [
                ...$photo,
                'is_primary' => $index === 0,
            ],
            $result,
            array_keys($result)
        ));
    }

    private function normalizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->cleanText($key) : $key;
            if (is_array($item)) {
                $result[$cleanKey] = $this->normalizeArray($item);
                continue;
            }

            $result[$cleanKey] = is_string($item) ? $this->cleanText($item) : $item;
        }

        return $result;
    }

    private function cleanText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function cleanDescription(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $withoutDangerousTags = preg_replace(
            '/<(script|style|iframe|object|embed|form|input|button|textarea|select|option|link|meta)[^>]*>.*?<\/\1>/is',
            '',
            $trimmed
        );

        $safeHtml = preg_replace(
            '/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i',
            '',
            $withoutDangerousTags ?? $trimmed
        );

        $safeHtml = preg_replace(
            '/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i',
            '',
            $safeHtml ?? ''
        );

        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote>';
        $sanitized = strip_tags($safeHtml ?? '', $allowedTags);
        $sanitized = preg_replace('/(?:<p>\s*<\/p>\s*)+/i', '', $sanitized ?? '');
        $sanitized = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $sanitized ?? '');
        $sanitized = trim((string) $sanitized);

        return $sanitized !== '' ? $sanitized : null;
    }

    private function generateSpu(string $brand): string
    {
        $prefix = strtoupper(Str::of($brand)->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->value());
        $prefix = $prefix !== '' ? $prefix : 'ENTRAVERSE';

        do {
            $candidate = sprintf('%s-%s', $prefix, Str::upper(Str::random(8)));
        } while (Product::query()->where('spu', $candidate)->exists());

        return $candidate;
    }
}
