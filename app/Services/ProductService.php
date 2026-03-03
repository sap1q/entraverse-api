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
            'description' => $this->cleanText((string) ($validated['description'] ?? $product?->description ?? '')),
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
            $item['stock'] = $stock;
            $item['purchase_price'] = (float) ($item['purchase_price'] ?? 0);
            $item['purchase_price_idr'] = (float) ($item['purchase_price_idr'] ?? 0);

            $normalized[] = $this->normalizeArray($item);
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
     * @return array<int, array<string, mixed>|string>
     */
    private function resolvePhotos(mixed $photos, array $uploadedImages, array $existingPhotos): array
    {
        if ($uploadedImages !== []) {
            $mapped = [];
            foreach ($uploadedImages as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $path = $file->store('products', 'public');
                $mapped[] = [
                    'url' => '/storage/' . ltrim($path, '/'),
                    'alt' => null,
                    'is_primary' => $index === 0,
                ];
            }

            return $mapped;
        }

        if (is_array($photos)) {
            return $this->normalizeArray($photos);
        }

        return $this->normalizeArray($existingPhotos);
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
