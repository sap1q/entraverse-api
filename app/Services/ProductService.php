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
    private const DEFAULT_WARRANTY_VARIANT_NAME = 'Garansi';
    private const DEFAULT_WARRANTY_OPTIONS = ['Tanpa Garansi', 'Toko - 1 Tahun'];
    private const DEFAULT_CURRENCY_SURCHARGE = 50.0;
    private const CURRENCIES_WITH_SURCHARGE = ['USD', 'SGD'];
    private const ACTIVATED_SYNC_STATUSES = [
        'activate',
        'active',
        'success',
        'synced',
        'imported_from_jurnal',
        'created',
        'updated',
    ];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 12), 100));
        $driver = DB::connection()->getDriverName();
        $status = strtolower(trim((string) ($filters['status'] ?? $filters['product_status'] ?? '')));
        $excludeFailedSync = filter_var($filters['exclude_failed_sync'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $onlySyncActivated = filter_var($filters['only_sync_activated'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return Product::query()
            ->when($status !== '', fn (Builder $query) => $query->whereRaw('LOWER(product_status) = ?', [$status]))
            ->when($filters['category_id'] ?? null, fn (Builder $query, string $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['brand'] ?? null, fn (Builder $query, string $brand) => $query->where('brand', $brand))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($onlySyncActivated, function (Builder $query) use ($driver) {
                if ($driver === 'pgsql') {
                    $query->whereIn(
                        DB::raw("LOWER(COALESCE(mekari_status->>'sync_status', ''))"),
                        self::ACTIVATED_SYNC_STATUSES
                    );
                    return;
                }

                $query->whereIn(
                    DB::raw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(mekari_status, '$.sync_status')), ''))"),
                    self::ACTIVATED_SYNC_STATUSES
                );
            })
            ->when($excludeFailedSync, function (Builder $query) use ($driver) {
                if ($driver === 'pgsql') {
                    $query->whereRaw("LOWER(COALESCE(mekari_status->>'sync_status', '')) <> 'failed'");
                    return;
                }

                $query->whereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(mekari_status, '$.sync_status')), '')) <> 'failed'");
            })
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
        $inventory = $this->normalizeInventory($inventory);

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
        $category = $this->resolveCategoryForPricing($categoryId, $categoryName);

        if ($categoryName === '' && $categoryId !== '') {
            $categoryName = (string) (Category::query()->where('id', $categoryId)->value('name') ?? '');
        }

        $variantPricing = $this->recalculateVariantPricing($variantPricing, $category);

        return [
            'name' => $this->cleanText((string) ($validated['name'] ?? $product?->name ?? '')),
            'category' => $categoryName,
            'category_id' => $categoryId !== '' ? $categoryId : null,
            'brand' => $this->cleanText((string) ($validated['brand'] ?? $product?->brand ?? '')),
            'description' => $this->cleanDescription((string) ($validated['description'] ?? $product?->description ?? '')),
            'trade_in' => (bool) ($validated['trade_in'] ?? $product?->trade_in ?? false),
            'inventory' => $inventory,
            'variants' => $this->normalizeVariantsWithDefaults($validated['variants'] ?? ($product?->variants ?? [])),
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

    private function resolveCategoryForPricing(string $categoryId, string $categoryName): ?Category
    {
        if ($categoryId !== '') {
            return Category::query()->find($categoryId)
                ?? Category::query()->withTrashed()->find($categoryId);
        }

        if ($categoryName === '') {
            return null;
        }

        return Category::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
            ->first();
    }

    private function recalculateVariantPricing(array $variantPricing, ?Category $category): array
    {
        if ($variantPricing === []) {
            return [];
        }

        if (! $category) {
            return $variantPricing;
        }

        $minMarginPercent = max(0, $this->toFloat($category->min_margin));
        $fees = is_array($category->fees) ? $category->fees : [];
        $warrantyComponents = $this->extractWarrantyComponents($category->program_garansi);
        $marketplaceChannel = $this->resolveFeeChannel($fees, ['marketplace', 'tokopedia_tiktok']);
        $shopeeChannel = $this->resolveFeeChannel($fees, ['shopee']);
        $entraverseChannel = $this->resolveFeeChannel($fees, ['entraverse']);

        if (($entraverseChannel['components'] ?? []) === []) {
            // Fallback: jika fee Entraverse belum diisi, ikutkan fee marketplace lebih dulu.
            $entraverseChannel = $marketplaceChannel;
        }

        return array_map(function (array $item) use (
            $minMarginPercent,
            $marketplaceChannel,
            $shopeeChannel,
            $entraverseChannel,
            $warrantyComponents
        ): array {
            $purchasePrice = max(0, $this->toFloat($item['purchase_price'] ?? 0));
            $exchangeValue = max(0, $this->toFloat($item['exchange_value'] ?? ($item['exchange_rate'] ?? 0)));
            $arrivalCost = max(0, $this->toFloat($item['arrival_cost'] ?? 0));
            $shippingCost = max(0, $this->toFloat($item['shipping_cost'] ?? 0));
            $currency = strtoupper(trim((string) ($item['currency'] ?? '')));
            $currencySurcharge = in_array($currency, self::CURRENCIES_WITH_SURCHARGE, true)
                ? self::DEFAULT_CURRENCY_SURCHARGE
                : 0.0;

            $landedCost = ($purchasePrice * $exchangeValue) + $arrivalCost + $shippingCost;
            $baseRecommended = round(($landedCost + $currencySurcharge) * (1 + ($minMarginPercent / 100)));

            $warrantyOption = $this->extractWarrantyOption($item);
            $warrantyAdjustment = $this->calculateWarrantyAdjustment(
                $warrantyComponents,
                $warrantyOption,
                $baseRecommended
            );
            $baseWithWarranty = max(0, $baseRecommended + $warrantyAdjustment);

            $entraverseFee = $this->calculateFeeTotals($entraverseChannel, $baseWithWarranty);
            $tokopediaFee = $this->calculateFeeTotals($marketplaceChannel, $baseWithWarranty);
            $shopeeFee = $this->calculateFeeTotals($shopeeChannel, $baseWithWarranty);

            $item['purchase_price_idr'] = (float) round($landedCost);
            $item['offline_price'] = (float) round($baseWithWarranty);
            $item['entraverse_price'] = (float) round($baseWithWarranty + $entraverseFee['amount_total']);
            $item['tokopedia_price'] = (float) round($baseWithWarranty + $tokopediaFee['amount_total']);
            $item['tiktok_price'] = (float) round($baseWithWarranty + $tokopediaFee['amount_total']);
            $item['shopee_price'] = (float) round($baseWithWarranty + $shopeeFee['amount_total']);
            $item['tokopedia_fee'] = (float) $tokopediaFee['percent_total'];
            $item['tiktok_fee'] = (float) $tokopediaFee['percent_total'];
            $item['shopee_fee'] = (float) $shopeeFee['percent_total'];

            return $item;
        }, $variantPricing);
    }

    private function resolveFeeChannel(array $fees, array $keys): array
    {
        foreach ($keys as $key) {
            $candidate = $fees[$key] ?? null;
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return ['components' => []];
    }

    private function calculateFeeTotals(array $channel, float $basePrice): array
    {
        $components = is_array($channel['components'] ?? null) ? $channel['components'] : [];
        $amountTotal = 0.0;
        $percentTotal = 0.0;

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $value = max(0, $this->toFloat($component['value'] ?? 0));
            $minValue = max(0, $this->toFloat($component['min'] ?? 0));
            $maxValue = max(0, $this->toFloat($component['max'] ?? 0));
            $valueType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
            $isAmount = $valueType === 'amount' || $valueType === 'rp';
            $fee = $isAmount ? $value : ($basePrice * ($value / 100));

            if ($minValue > 0) {
                $fee = max($fee, $minValue);
            }
            if ($maxValue > 0) {
                $fee = min($fee, $maxValue);
            }

            $amountTotal += max(0, $fee);
            if (! $isAmount) {
                $percentTotal += $value;
            }
        }

        return [
            'amount_total' => round($amountTotal),
            'percent_total' => round($percentTotal, 4),
        ];
    }

    private function extractWarrantyComponents(mixed $programGaransi): array
    {
        $components = [];
        $push = function (array $component) use (&$components): void {
            $label = $this->normalizeLabel((string) ($component['label'] ?? ''));
            if ($label === '') {
                return;
            }

            $dedupeKey = strtolower($label);
            foreach ($components as $existing) {
                if (($existing['key'] ?? '') === $dedupeKey) {
                    return;
                }
            }

            $valueType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
            $components[] = [
                'key' => $dedupeKey,
                'label' => $label,
                'valueType' => $valueType === 'amount' ? 'amount' : 'percent',
                'value' => max(0, $this->toFloat($component['value'] ?? 0)),
            ];
        };

        $parseComponent = function (mixed $row) use (&$push): void {
            if (is_string($row)) {
                $label = $this->normalizeLabel($row);
                if ($label !== '') {
                    $push([
                        'label' => $label,
                        'valueType' => 'percent',
                        'value' => 0,
                    ]);
                }
                return;
            }

            if (! is_array($row)) {
                return;
            }

            $push([
                'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
                'valueType' => (string) ($row['valueType'] ?? 'percent'),
                'value' => $row['value'] ?? 0,
            ]);
        };

        if (is_array($programGaransi)) {
            if (array_is_list($programGaransi)) {
                foreach ($programGaransi as $row) {
                    $parseComponent($row);
                }
                return $components;
            }

            $nested = $programGaransi['components'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $row) {
                    $parseComponent($row);
                }
            }

            return $components;
        }

        if (! is_string($programGaransi) || trim($programGaransi) === '') {
            return $components;
        }

        $decoded = json_decode($programGaransi, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->extractWarrantyComponents($decoded);
        }

        $labels = preg_split('/[\r\n,]+/', $programGaransi) ?: [];
        foreach ($labels as $label) {
            $parseComponent($label);
        }

        return $components;
    }

    private function extractWarrantyOption(array $item): string
    {
        $options = $item['options'] ?? null;
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                if ($this->normalizeLabel((string) $key) === 'garansi') {
                    return $this->normalizeLabel((string) $value);
                }
            }
        }

        $label = trim((string) ($item['label'] ?? ''));
        if ($label !== '' && preg_match('/garansi\s*:\s*([^\/|]+)/i', $label, $matches) === 1) {
            return $this->normalizeLabel((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function calculateWarrantyAdjustment(array $warrantyComponents, string $warrantyOption, float $baseRecommended): float
    {
        if ($warrantyOption === '') {
            return 0.0;
        }

        $lookup = strtolower($this->normalizeLabel($warrantyOption));
        foreach ($warrantyComponents as $component) {
            if (($component['key'] ?? '') !== $lookup) {
                continue;
            }

            $valueType = (string) ($component['valueType'] ?? 'percent');
            $value = max(0, $this->toFloat($component['value'] ?? 0));

            if ($valueType === 'amount') {
                return round($value);
            }

            return round($baseRecommended * ($value / 100));
        }

        return 0.0;
    }

    private function normalizeLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9,.\-]/', '', trim($value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } elseif (substr_count($normalized, '.') > 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
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

    private function normalizeInventory(array $inventory): array
    {
        $dimensions = is_array($inventory['dimensions_cm'] ?? null) ? $inventory['dimensions_cm'] : [];

        $inventory['dimensions_cm'] = [
            'length' => max(0, (float) ($dimensions['length'] ?? 0)),
            'width' => max(0, (float) ($dimensions['width'] ?? 0)),
            'height' => max(0, (float) ($dimensions['height'] ?? 0)),
        ];

        $inventory['volume_m3'] = max(0, (float) ($inventory['volume_m3'] ?? 0));

        if (array_key_exists('weight', $inventory)) {
            $inventory['weight'] = max(0, (int) $inventory['weight']);
        }

        if (array_key_exists('total_stock', $inventory)) {
            $inventory['total_stock'] = max(0, (int) $inventory['total_stock']);
        }

        if (array_key_exists('price', $inventory)) {
            $inventory['price'] = max(0, (float) $inventory['price']);
        }

        return $inventory;
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

    private function normalizeVariantsWithDefaults(mixed $value): array
    {
        $variants = $this->normalizeArray($value);
        $rows = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $name = $this->cleanText((string) ($variant['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $options = [];
            $rawOptions = is_array($variant['options'] ?? null) ? $variant['options'] : [];
            foreach ($rawOptions as $option) {
                $cleanOption = $this->cleanText((string) $option);
                if ($cleanOption !== '') {
                    $options[] = $cleanOption;
                }
            }

            $rows[] = [
                'name' => $name,
                'options' => array_values(array_unique($options)),
            ];
        }

        $warrantyIndex = null;
        foreach ($rows as $index => $variant) {
            if (strtolower((string) ($variant['name'] ?? '')) === strtolower(self::DEFAULT_WARRANTY_VARIANT_NAME)) {
                $warrantyIndex = $index;
                break;
            }
        }

        if ($warrantyIndex === null) {
            $rows[] = [
                'name' => self::DEFAULT_WARRANTY_VARIANT_NAME,
                'options' => self::DEFAULT_WARRANTY_OPTIONS,
            ];
        } else {
            $existingOptions = is_array($rows[$warrantyIndex]['options'] ?? null) ? $rows[$warrantyIndex]['options'] : [];
            $rows[$warrantyIndex]['name'] = self::DEFAULT_WARRANTY_VARIANT_NAME;
            $rows[$warrantyIndex]['options'] = array_values(array_unique([
                ...self::DEFAULT_WARRANTY_OPTIONS,
                ...$existingOptions,
            ]));
        }

        return $rows;
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
