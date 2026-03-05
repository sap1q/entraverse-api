<?php

declare(strict_types=1);

namespace App\Services\Mekari\Jurnal;

use App\Events\ProductSyncedToJurnal;
use App\Models\Product;
use App\Services\Mekari\Exceptions\MekariApiException;
use App\Services\Mekari\MekariService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class JurnalProductService
{
    private const DEFAULT_WARRANTY_VARIANT_NAME = 'Garansi';
    private const DEFAULT_WARRANTY_OPTIONS = ['Tanpa Garansi', 'Toko - 1 Tahun'];

    public function __construct(protected readonly MekariService $mekari) {}

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $params
     * @return array<string, mixed>
     */
    public function getProducts(array $params = []): array
    {
        return $this->mekari->request('GET', "{$this->jurnalBasePath()}/products", [
            'query' => $params,
        ]);
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $params
     * @return array{
     *     requested_page: int,
     *     fetched_pages: int,
     *     created: int,
     *     updated: int,
     *     failed_count: int,
     *     failed: array<int, array{jurnal_id: string|null, name: string|null, error: string}>,
     *     total_remote_count: int,
     *     imported_count: int
     * }
     */
    public function importProductsFromJurnal(array $params = [], int $maxPages = 1): array
    {
        $maxPages = max(1, $maxPages);
        $page = max(1, (int) ($params['page'] ?? 1));
        $created = 0;
        $updated = 0;
        $failed = [];
        $fetchedPages = 0;
        $importedCount = 0;
        $totalRemoteCount = 0;

        for ($pageIndex = 0; $pageIndex < $maxPages; $pageIndex++) {
            $query = [
                ...$params,
                'page' => $page,
            ];

            $response = $this->getProducts($query);
            $fetchedPages++;

            $items = Arr::get($response, 'products', []);
            if (! is_array($items) || $items === []) {
                break;
            }

            $totalRemoteCount += count($items);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                try {
                    $action = $this->upsertLocalProductFromJurnal($item);
                    if ($action === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }

                    $importedCount++;
                } catch (Throwable $exception) {
                    $failed[] = [
                        'jurnal_id' => Arr::get($item, 'id') !== null ? (string) Arr::get($item, 'id') : null,
                        'name' => Arr::get($item, 'name') !== null ? (string) Arr::get($item, 'name') : null,
                        'error' => $exception->getMessage(),
                    ];

                    Log::warning('Failed importing Jurnal product into Entraverse.', [
                        'jurnal_id' => Arr::get($item, 'id'),
                        'name' => Arr::get($item, 'name'),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $totalPages = max(1, (int) Arr::get($response, 'total_pages', 1));
            if ($page >= $totalPages) {
                break;
            }

            $page++;
        }

        return [
            'requested_page' => max(1, (int) ($params['page'] ?? 1)),
            'fetched_pages' => $fetchedPages,
            'created' => $created,
            'updated' => $updated,
            'failed_count' => count($failed),
            'failed' => $failed,
            'total_remote_count' => $totalRemoteCount,
            'imported_count' => $importedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createProduct(Product $product): array
    {
        try {
            $payload = ['product' => $this->transformProductToJurnal($product)];
            $response = $this->mekari->request('POST', "{$this->jurnalBasePath()}/products", [
                'body' => $payload,
            ]);

            $this->persistSyncResult($product, $response);

            return $response;
        } catch (Throwable $exception) {
            $this->markSyncAsFailed($product, $exception, 'create');

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateProduct(Product $product): array
    {
        if (empty($product->jurnal_id)) {
            return $this->createProduct($product);
        }

        try {
            $payload = ['product' => $this->transformProductToJurnal($product)];
            $response = $this->mekari->request('PATCH', "{$this->jurnalBasePath()}/products/{$product->jurnal_id}", [
                'body' => $payload,
            ]);

            $this->persistSyncResult($product, $response);

            return $response;
        } catch (Throwable $exception) {
            $this->markSyncAsFailed($product, $exception, 'update');

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deleteProduct(Product $product): ?array
    {
        if (empty($product->jurnal_id)) {
            return null;
        }

        try {
            $response = $this->mekari->request('DELETE', "{$this->jurnalBasePath()}/products/{$product->jurnal_id}");

            $product->forceFill([
                'jurnal_id' => null,
                'jurnal_metadata' => null,
                'last_synced_at' => now(),
                'mekari_status' => [
                    ...($product->mekari_status ?? []),
                    'sync_status' => 'deleted',
                    'last_sync' => now()->toISOString(),
                    'last_response' => $response,
                ],
            ])->save();

            return $response;
        } catch (Throwable $exception) {
            $this->markSyncAsFailed($product, $exception, 'delete');

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function syncProduct(Product $product): array
    {
        if (empty($product->jurnal_id)) {
            $remoteProductId = $this->findRemoteProductIdByCustomId($this->resolveCustomId($product));
            if ($remoteProductId !== null) {
                $product->forceFill(['jurnal_id' => $remoteProductId])->save();
                $product = $product->fresh();
            }
        }

        return empty($product->jurnal_id)
            ? $this->createProduct($product)
            : $this->updateProduct($product);
    }

    public function archiveProduct(Product $product): array
    {
        if (empty($product->jurnal_id)) {
            $response = $this->syncProduct($product);
            $product = $product->fresh();
            if (empty($product->jurnal_id)) {
                return $response;
            }
        }

        try {
            $response = $this->mekari->request('POST', "{$this->jurnalBasePath()}/products/{$product->jurnal_id}/deactivate");
            $this->persistArchiveState($product, $response, true);

            return $response;
        } catch (Throwable $exception) {
            $this->markSyncAsFailed($product, $exception, 'archive');

            throw $exception;
        }
    }

    public function unarchiveProduct(Product $product): array
    {
        if (empty($product->jurnal_id)) {
            $response = $this->syncProduct($product);
            $product = $product->fresh();
            if (empty($product->jurnal_id)) {
                return $response;
            }
        }

        try {
            $response = $this->mekari->request('POST', "{$this->jurnalBasePath()}/products/{$product->jurnal_id}/activate");
            $this->persistArchiveState($product, $response, false);

            return $response;
        } catch (Throwable $exception) {
            $this->markSyncAsFailed($product, $exception, 'unarchive');

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformProductToJurnal(Product $product): array
    {
        $variantPricing = collect($product->variant_pricing);
        $primaryVariant = $variantPricing->first();

        $inventory = is_array($product->inventory) ? $product->inventory : [];
        $stockFromVariants = $variantPricing
            ->pluck('stock')
            ->filter(static fn ($value) => is_numeric($value))
            ->sum();

        $stock = max(
            (int) ($product->stock ?? 0),
            (int) $stockFromVariants,
            (int) Arr::get($inventory, 'total_stock', 0)
        );

        $price = $this->resolveNumericValue(
            Arr::get($primaryVariant, 'entraverse_price'),
            Arr::get($primaryVariant, 'selling_price'),
            Arr::get($inventory, 'price'),
            0
        );

        $cost = $this->resolveNumericValue(
            Arr::get($primaryVariant, 'purchase_price_idr'),
            Arr::get($primaryVariant, 'cost'),
            Arr::get($inventory, 'cost'),
            0
        );

        $productCode = trim((string) $product->spu);
        $this->validateProductForSync($product, $productCode, $price);

        return array_filter([
            'name' => $product->name,
            'product_code' => $productCode,
            'custom_id' => $productCode,
            'description' => $product->description,
            'sell_price_per_unit' => $price,
            'buy_price_per_unit' => $cost,
            'track_inventory' => $stock > 0 || $product->product_status === 'active',
            'is_bought' => true,
            'is_sold' => true,
            'unit_name' => 'pcs',
            'barcode' => $product->barcode,
            'archive' => $product->product_status !== 'active',
            'buy_account_number' => '5-50000',
            'sell_account_number' => '4-40000',
            'taxable_buy' => false,
            'taxable_sell' => false,
            'buy_tax_id' => null,
            'sell_tax_id' => null,
            'weight' => $this->resolveNumericValue($product->weight, Arr::get($inventory, 'weight')),
        ], static fn ($value) => $value !== null);
    }

    protected function validateProductForSync(Product $product, string $productCode, float $sellPrice): void
    {
        $errors = [];

        if ($productCode === '') {
            $errors[] = 'SPU/SKU kosong';
        }

        if ($sellPrice <= 0) {
            $errors[] = 'harga jual harus lebih dari 0';
        }

        if ($errors === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Produk %s tidak memenuhi syarat sinkronisasi Jurnal: %s.',
            (string) $product->id,
            implode('; ', $errors)
        ));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function persistSyncResult(Product $product, array $response): void
    {
        $jurnalId = Arr::get($response, 'data.id')
            ?? Arr::get($response, 'product.id')
            ?? Arr::get($response, 'data.product.id')
            ?? Arr::get($response, 'products.0.id')
            ?? $product->jurnal_id;

        $product->forceFill([
            'jurnal_id' => $jurnalId,
            'jurnal_metadata' => $response,
            'last_synced_at' => now(),
            'mekari_status' => [
                ...($product->mekari_status ?? []),
                'sync_status' => 'activate',
                'last_sync' => now()->toISOString(),
                'mekari_id' => $jurnalId,
            ],
        ])->save();

        Log::info('Product synced to Jurnal.', [
            'product_id' => $product->id,
            'jurnal_id' => $jurnalId,
        ]);

        ProductSyncedToJurnal::dispatch($product->fresh(), $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function persistArchiveState(Product $product, array $response, bool $archived): void
    {
        $metadata = is_array($product->jurnal_metadata) ? $product->jurnal_metadata : [];

        $product->forceFill([
            'jurnal_metadata' => array_replace_recursive($metadata, $response),
            'last_synced_at' => now(),
            'mekari_status' => [
                ...($product->mekari_status ?? []),
                'sync_status' => $archived ? 'archived' : 'active',
                'last_sync' => now()->toISOString(),
                'last_action' => $archived ? 'archive' : 'unarchive',
                'last_response' => $response,
            ],
        ])->save();
    }

    protected function markSyncAsFailed(Product $product, Throwable $exception, string $action): void
    {
        $responseData = $exception instanceof MekariApiException ? $exception->getResponseData() : null;
        $statusCode = $exception instanceof MekariApiException ? $exception->getStatusCode() : null;

        $product->forceFill([
            'last_synced_at' => now(),
            'mekari_status' => [
                ...($product->mekari_status ?? []),
                'sync_status' => 'failed',
                'last_sync' => now()->toISOString(),
                'last_action' => $action,
                'last_error' => $exception->getMessage(),
                'last_error_at' => now()->toISOString(),
                'last_status_code' => $statusCode,
                'last_response' => $responseData,
            ],
        ])->save();

        Log::warning('Mekari Jurnal product sync failed.', [
            'product_id' => $product->id,
            'jurnal_id' => $product->jurnal_id,
            'action' => $action,
            'status_code' => $statusCode,
            'error' => $exception->getMessage(),
            'response' => $responseData,
        ]);
    }

    protected function resolveNumericValue(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    protected function jurnalBasePath(): string
    {
        return rtrim((string) config('services.mekari.jurnal_base_path', '/public/jurnal/api/v1'), '/');
    }

    protected function resolveCustomId(Product $product): string
    {
        return (string) ($product->spu ?: ('ENTRAVERSE-'.$product->id));
    }

    protected function findRemoteProductIdByCustomId(string $customId): ?string
    {
        try {
            $response = $this->mekari->request('GET', "{$this->jurnalBasePath()}/products/{$customId}");
            $remoteId = Arr::get($response, 'product.id')
                ?? Arr::get($response, 'data.id');

            if ($remoteId === null) {
                return null;
            }

            return (string) $remoteId;
        } catch (MekariApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    protected function upsertLocalProductFromJurnal(array $remote): string
    {
        $jurnalId = trim((string) Arr::get($remote, 'id', ''));
        if ($jurnalId === '') {
            throw new \RuntimeException('Missing Jurnal product ID.');
        }

        $spuCandidate = trim((string) (Arr::get($remote, 'product_code') ?: Arr::get($remote, 'custom_id') ?: ''));
        $name = trim((string) Arr::get($remote, 'name', ''));
        $categoryName = trim((string) (Arr::get($remote, 'product_categories.0.name')
            ?: Arr::get($remote, 'product_categories_string')
            ?: 'Uncategorized'));

        $product = Product::query()
            ->where('jurnal_id', $jurnalId)
            ->first();

        if ($product === null && $spuCandidate !== '') {
            $product = Product::query()
                ->where('spu', $spuCandidate)
                ->first();
        }

        $action = $product === null ? 'created' : 'updated';
        $product ??= new Product();

        $normalizedSpu = $this->resolveImportedSpu($product, $spuCandidate, $jurnalId);
        $quantity = (int) Arr::get($remote, 'quantity_available', Arr::get($remote, 'quantity', 0));
        $sellPrice = (float) Arr::get($remote, 'sell_price_per_unit', 0);
        $buyPrice = (float) Arr::get($remote, 'buy_price_per_unit', 0);
        $weight = (int) Arr::get($remote, 'weight', 0);
        $isArchived = (bool) Arr::get($remote, 'archive', false);
        $isActive = (bool) Arr::get($remote, 'active', true);
        $productStatus = ($isArchived || ! $isActive) ? 'inactive' : 'active';
        $safeName = $name !== '' ? $name : "Jurnal Product {$jurnalId}";
        $safeCategory = $categoryName !== '' ? $categoryName : 'Uncategorized';

        $existingInventory = is_array($product->inventory) ? $product->inventory : [];
        $imageUrl = $this->resolveRemoteImageUrl($remote);
        $photos = $imageUrl !== ''
            ? [[
                'url' => $imageUrl,
                'alt' => $safeName,
                'is_primary' => true,
            ]]
            : (is_array($product->photos) ? $product->photos : []);

        $variantPricing = [[
            'sku' => $normalizedSpu,
            'label' => 'Default',
            'stock' => $quantity,
            'warehouse' => 'Gudang Utama',
            'warehouse_stock' => [
                'Gudang Utama' => $quantity,
            ],
            'offline_price' => $sellPrice,
            'entraverse_price' => $sellPrice,
            'purchase_price' => $buyPrice,
            'purchase_price_idr' => $buyPrice,
        ]];

        $product->fill([
            'name' => $safeName,
            'category' => $safeCategory,
            'description' => (string) Arr::get($remote, 'description', ''),
            'spu' => $normalizedSpu,
            'stock' => $quantity,
            'inventory' => [
                ...$existingInventory,
                'price' => $sellPrice,
                'cost' => $buyPrice,
                'total_stock' => $quantity,
                'weight' => $weight,
            ],
            'photos' => $photos,
            'variants' => $this->normalizeVariantsWithDefaults($product->variants),
            'variant_pricing' => $variantPricing,
            'product_status' => $productStatus,
            'jurnal_id' => $jurnalId,
            'jurnal_metadata' => [
                'product' => $remote,
                'imported_at' => now()->toISOString(),
            ],
            'last_synced_at' => now(),
            'mekari_status' => [
                ...($product->mekari_status ?? []),
                'sync_status' => 'imported_from_jurnal',
                'last_sync' => now()->toISOString(),
                'mekari_id' => $jurnalId,
            ],
        ]);

        $product->save();

        return $action;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array{name: string, options: array<int, string>}>
     */
    protected function normalizeVariantsWithDefaults(mixed $value): array
    {
        $rows = [];

        if (is_array($value)) {
            foreach ($value as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $name = trim((string) ($variant['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $options = [];
                $rawOptions = is_array($variant['options'] ?? null) ? $variant['options'] : [];
                foreach ($rawOptions as $option) {
                    $cleanOption = trim((string) $option);
                    if ($cleanOption !== '') {
                        $options[] = $cleanOption;
                    }
                }

                $rows[] = [
                    'name' => $name,
                    'options' => array_values(array_unique($options)),
                ];
            }
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

    protected function resolveRemoteImageUrl(array $remote): string
    {
        $candidates = [
            Arr::get($remote, 'image.url'),
            Arr::get($remote, 'image'),
            Arr::get($remote, 'photo.url'),
            Arr::get($remote, 'photo'),
            Arr::get($remote, 'default_image.url'),
            Arr::get($remote, 'default_image'),
            Arr::get($remote, 'images.0.url'),
            Arr::get($remote, 'images.0.original_url'),
            Arr::get($remote, 'images.0'),
            Arr::get($remote, 'pictures.0.url'),
            Arr::get($remote, 'pictures.0'),
            Arr::get($remote, 'thumbnail.url'),
            Arr::get($remote, 'thumbnail'),
            Arr::get($remote, 'product_image'),
            Arr::get($remote, 'photo_url'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeRemoteImageUrl($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    protected function normalizeRemoteImageUrl(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $baseUrl = rtrim((string) config('services.mekari.base_url', 'https://api.mekari.com'), '/');
            return $baseUrl.$url;
        }

        if (preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}(\/.*)?$/', $url) === 1) {
            return 'https://'.$url;
        }

        return '';
    }

    protected function resolveImportedSpu(Product $product, string $candidate, string $jurnalId): string
    {
        $base = trim($candidate) !== '' ? trim($candidate) : "JRNL-{$jurnalId}";
        $base = Str::upper((string) preg_replace('/[^A-Za-z0-9\-_\.]/', '-', $base));
        $base = Str::limit($base, 50, '');

        if ($base === '') {
            $base = Str::limit("JRNL-{$jurnalId}", 50, '');
        }

        $spu = $base;
        $counter = 1;

        while (
            Product::query()
                ->where('spu', $spu)
                ->when($product->exists, fn ($query) => $query->where('id', '!=', $product->id))
                ->exists()
        ) {
            $suffix = "-{$counter}";
            $spu = Str::limit($base, max(1, 50 - strlen($suffix)), '').$suffix;
            $counter++;

            if ($counter > 999) {
                break;
            }
        }

        return $spu;
    }
}
