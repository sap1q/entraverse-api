<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\Product;
use App\Models\StockMutation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    private const DEFAULT_WAREHOUSE = 'Gudang Utama';
    private const LOW_STOCK_THRESHOLD = 10;

    public function listInventory(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseFilter = trim((string) ($filters['warehouse'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $driver = DB::connection()->getDriverName();

        $products = Product::query()
            ->select(['id', 'name', 'spu', 'brand', 'inventory', 'photos', 'variant_pricing', 'updated_at'])
            ->when($search !== '', function ($query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(fn ($q) => $q
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('spu', 'ilike', "%{$search}%")
                        ->orWhere('brand', 'ilike', "%{$search}%"));
                    return;
                }

                $keyword = '%' . strtolower($search) . '%';
                $query->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(spu) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(brand) LIKE ?', [$keyword]));
            })
            ->orderByDesc('updated_at')
            ->get();

        $allRows = $products->flatMap(function (Product $product): array {
            $rows = [];
            foreach ($this->extractVariantRows($product) as $index => $variant) {
                foreach ($this->toInventoryRows($product, $variant, $index) as $inventoryRow) {
                    $rows[] = $inventoryRow;
                }
            }
            return $rows;
        })->values();

        $warehouseScoped = $warehouseFilter !== ''
            ? $allRows->filter(
                fn (array $row): bool => strcasecmp((string) $row['warehouse'], $warehouseFilter) === 0
            )->values()
            : $allRows;

        $searchScoped = $search !== ''
            ? $warehouseScoped->filter(function (array $row) use ($search): bool {
                $keyword = mb_strtolower($search);
                $haystacks = [
                    mb_strtolower((string) ($row['product_name'] ?? '')),
                    mb_strtolower((string) ($row['sku'] ?? '')),
                    mb_strtolower((string) ($row['variant_name'] ?? '')),
                ];

                foreach ($haystacks as $value) {
                    if ($value !== '' && str_contains($value, $keyword)) {
                        return true;
                    }
                }

                return false;
            })->values()
            : $warehouseScoped;

        $statusScoped = $this->applyStatusFilter($searchScoped, $statusFilter);

        $total = $statusScoped->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);
        $offset = ($currentPage - 1) * $perPage;

        $paginated = $statusScoped
            ->slice($offset, $perPage)
            ->values();

        return [
            'rows' => $paginated->all(),
            'stats' => [
                'total_sku' => $searchScoped
                    ->map(fn (array $row): string => (string) $row['product_id'] . '|' . (string) $row['sku'])
                    ->unique()
                    ->count(),
                'low_stock_alert' => $searchScoped->filter(fn (array $row): bool => (int) $row['current_stock'] > 0 && (int) $row['current_stock'] < self::LOW_STOCK_THRESHOLD)->count(),
                'out_of_stock' => $searchScoped->filter(fn (array $row): bool => (int) $row['current_stock'] === 0)->count(),
            ],
            'warehouses' => $allRows
                ->pluck('warehouse')
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->unique()
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    public function getMutations(string $productId, string $variantSku, int $limit = 5): EloquentCollection
    {
        return StockMutation::query()
            ->with(['product:id,name,spu', 'admin:id,name,email'])
            ->where('product_id', $productId)
            ->where('variant_sku', $variantSku)
            ->latest()
            ->limit(max(1, min($limit, 50)))
            ->get();
    }

    public function adjust(Admin $admin, array $payload): array
    {
        return DB::transaction(function () use ($admin, $payload): array {
            /** @var Product $product */
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail((string) $payload['product_id']);

            $variantRows = $this->extractVariantRows($product);
            $targetSku = trim((string) $payload['variant_sku']);
            $targetIndex = $this->findVariantIndex($product, $variantRows, $targetSku);

            if ($targetIndex === null) {
                throw ValidationException::withMessages([
                    'variant_sku' => ['SKU varian tidak ditemukan untuk produk ini.'],
                ]);
            }

            $type = (string) $payload['type'];
            $quantity = max(1, (int) $payload['quantity']);
            $allowNegative = (bool) ($payload['allow_negative'] ?? false);
            $direction = (string) ($payload['direction'] ?? 'decrement');

            $variant = $variantRows[$targetIndex];
            $delta = $this->resolveDelta($type, $quantity, $direction);
            $targetWarehouse = trim((string) ($payload['warehouse'] ?? ''));
            if ($targetWarehouse === '') {
                $targetWarehouse = $this->resolveWarehouse($product, $variant);
            }

            $warehouseStock = $this->normalizeWarehouseStock(
                $variant['warehouse_stock'] ?? null,
                $targetWarehouse,
                (int) ($variant['stock'] ?? 0),
                true
            );

            $currentWarehouseStock = (int) ($warehouseStock[$targetWarehouse] ?? 0);
            $nextWarehouseStock = $currentWarehouseStock + $delta;

            if (! $allowNegative && $nextWarehouseStock < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Stok gudang tidak boleh kurang dari 0 tanpa izin allow_negative.'],
                ]);
            }

            $warehouseStock[$targetWarehouse] = $nextWarehouseStock;
            $variant['sku'] = $targetSku;
            $variant['warehouse'] = $targetWarehouse;
            $variant['warehouse_stock'] = $warehouseStock;
            $variant['stock'] = (int) collect($warehouseStock)->sum();
            $variant['updated_at'] = now()->toISOString();
            $variantRows[$targetIndex] = $variant;

            $totalStock = (int) collect($variantRows)->sum(fn (array $row): int => (int) ($row['stock'] ?? 0));
            $inventory = is_array($product->inventory) ? $product->inventory : [];
            $inventory['total_stock'] = $totalStock;

            $product->variant_pricing = array_values($variantRows);
            $product->inventory = $inventory;
            $product->stock = $totalStock;
            $product->save();

            $reason = trim((string) ($payload['reason'] ?? ''));
            $note = trim((string) ($payload['note'] ?? ''));

            $mutation = StockMutation::query()->create([
                'product_id' => (string) $product->id,
                'variant_sku' => $targetSku,
                'type' => $type,
                'quantity' => $delta,
                'reference' => trim((string) ($payload['reference'] ?? 'manual')) ?: 'manual',
                'note' => $this->composeNote($reason, $note, $targetWarehouse, $currentWarehouseStock, $nextWarehouseStock),
                'user_id' => (string) $admin->id,
            ]);

            $updatedProduct = $product->refresh();
            $updatedRows = $this->extractVariantRows($updatedProduct);
            $updatedVariant = $updatedRows[$targetIndex] ?? $variantRows[$targetIndex];
            $inventoryRows = $this->toInventoryRows($updatedProduct, $updatedVariant, $targetIndex);
            $targetRow = collect($inventoryRows)->first(
                fn (array $row): bool => strcasecmp((string) $row['warehouse'], $targetWarehouse) === 0
            ) ?? $inventoryRows[0];

            return [
                'row' => $targetRow,
                'mutation' => $mutation->load(['product:id,name,spu', 'admin:id,name,email']),
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVariantRows(Product $product): array
    {
        $variantPricing = is_array($product->variant_pricing) ? $product->variant_pricing : [];
        $rows = [];

        foreach ($variantPricing as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['items']) && is_array($entry['items'])) {
                foreach ($entry['items'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $normalized = $item;
                    if (! isset($normalized['currency']) && isset($entry['currency'])) {
                        $normalized['currency'] = $entry['currency'];
                    }

                    $rows[] = $normalized;
                }

                continue;
            }

            $rows[] = $entry;
        }

        if ($rows === []) {
            $inventory = is_array($product->inventory) ? $product->inventory : [];
            $rows[] = [
                'label' => 'Default',
                'stock' => (int) ($inventory['total_stock'] ?? $product->stock ?? 0),
                'sku' => $product->spu ?: sprintf('SKU-%s', $product->id),
                'warehouse' => $inventory['warehouse'] ?? self::DEFAULT_WAREHOUSE,
            ];
        }

        return array_values(array_map(function (array $row, int $index) use ($product): array {
            $normalized = $row;
            $normalized['sku'] = $this->resolveSku($product, $row, $index);
            $normalized['warehouse'] = $this->resolveWarehouse($product, $row);
            $normalized['label'] = (string) ($row['label'] ?? $row['variant_name'] ?? $row['variant_code'] ?? 'Default');

            $normalized['warehouse_stock'] = $this->normalizeWarehouseStock(
                $row['warehouse_stock'] ?? null,
                (string) $normalized['warehouse'],
                (int) ($row['stock'] ?? 0),
                true
            );
            $normalized['stock'] = (int) collect($normalized['warehouse_stock'])->sum();

            return $normalized;
        }, $rows, array_keys($rows)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toInventoryRows(Product $product, array $variant, int $index): array
    {
        $rows = [];
        $warehouseStock = $this->normalizeWarehouseStock(
            $variant['warehouse_stock'] ?? null,
            $this->resolveWarehouse($product, $variant),
            (int) ($variant['stock'] ?? 0),
            true
        );

        $lastUpdate = is_string($variant['updated_at'] ?? null) && trim((string) $variant['updated_at']) !== ''
            ? (string) $variant['updated_at']
            : optional($product->updated_at)->toISOString();

        foreach ($warehouseStock as $warehouse => $stock) {
            $warehouseName = trim((string) $warehouse);
            if ($warehouseName === '') {
                continue;
            }

            $stockValue = (int) $stock;
            $rows[] = [
                'id' => sprintf('%s:%s:%d:%s', (string) $product->id, (string) ($variant['sku'] ?? ''), $index, $warehouseName),
                'product_id' => (string) $product->id,
                'product_name' => (string) $product->name,
                'product_spu' => (string) ($product->spu ?? ''),
                'product_image' => $this->resolveProductImage($product),
                'sku' => (string) ($variant['sku'] ?? ''),
                'variant_name' => (string) ($variant['label'] ?? 'Default'),
                'warehouse' => $warehouseName,
                'current_stock' => $stockValue,
                'variant_total_stock' => (int) collect($warehouseStock)->sum(),
                'status' => $this->resolveStatus($stockValue),
                'last_update' => $lastUpdate,
            ];
        }

        if ($rows === []) {
            $rows[] = [
                'id' => sprintf('%s:%s:%d:%s', (string) $product->id, (string) ($variant['sku'] ?? ''), $index, self::DEFAULT_WAREHOUSE),
                'product_id' => (string) $product->id,
                'product_name' => (string) $product->name,
                'product_spu' => (string) ($product->spu ?? ''),
                'product_image' => $this->resolveProductImage($product),
                'sku' => (string) ($variant['sku'] ?? ''),
                'variant_name' => (string) ($variant['label'] ?? 'Default'),
                'warehouse' => self::DEFAULT_WAREHOUSE,
                'current_stock' => 0,
                'variant_total_stock' => 0,
                'status' => $this->resolveStatus(0),
                'last_update' => $lastUpdate,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function findVariantIndex(Product $product, array $rows, string $variantSku): ?int
    {
        foreach ($rows as $index => $row) {
            $rowSku = $this->resolveSku($product, $row, $index);
            if (strcasecmp($rowSku, $variantSku) === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyStatusFilter(Collection $rows, string $statusFilter): Collection
    {
        return match (strtolower($statusFilter)) {
            'low' => $rows->filter(fn (array $row): bool => (int) $row['current_stock'] > 0 && (int) $row['current_stock'] < self::LOW_STOCK_THRESHOLD)->values(),
            'empty' => $rows->filter(fn (array $row): bool => (int) $row['current_stock'] === 0)->values(),
            'safe' => $rows->filter(fn (array $row): bool => (int) $row['current_stock'] >= self::LOW_STOCK_THRESHOLD)->values(),
            default => $rows,
        };
    }

    /**
     * @return array<string, int>
     */
    private function normalizeWarehouseStock(
        mixed $warehouseStock,
        string $fallbackWarehouse,
        int $fallbackStock,
        bool $allowNegative = false
    ): array {
        $normalized = [];

        if (is_array($warehouseStock)) {
            foreach ($warehouseStock as $warehouse => $qty) {
                $warehouseName = trim(is_string($warehouse) ? $warehouse : '');
                if ($warehouseName === '') {
                    continue;
                }

                $value = (int) $qty;
                $normalized[$warehouseName] = $allowNegative ? $value : max(0, $value);
            }
        }

        if ($normalized === []) {
            $value = $allowNegative ? $fallbackStock : max(0, $fallbackStock);
            $normalized[$fallbackWarehouse] = $value;
        }

        return $normalized;
    }

    private function resolveDelta(string $type, int $quantity, string $direction): int
    {
        return match ($type) {
            'in' => $quantity,
            'out' => -$quantity,
            default => strtolower($direction) === 'increment' ? $quantity : -$quantity,
        };
    }

    private function resolveStatus(int $stock): string
    {
        if ($stock <= 0) {
            return 'empty';
        }

        if ($stock < self::LOW_STOCK_THRESHOLD) {
            return 'low';
        }

        return 'safe';
    }

    private function resolveSku(Product $product, array $variant, int $index): string
    {
        $candidates = [
            $variant['sku'] ?? null,
            $variant['sku_seller'] ?? null,
            $variant['variant_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        $spu = trim((string) ($product->spu ?? ''));
        if ($spu !== '') {
            return sprintf('%s-%d', $spu, $index + 1);
        }

        return sprintf('SKU-%s-%d', (string) $product->id, $index + 1);
    }

    private function resolveWarehouse(Product $product, array $variant): string
    {
        $candidate = trim((string) ($variant['warehouse'] ?? $variant['warehouse_name'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $inventory = is_array($product->inventory) ? $product->inventory : [];
        $inventoryWarehouse = trim((string) ($inventory['warehouse'] ?? ''));
        if ($inventoryWarehouse !== '') {
            return $inventoryWarehouse;
        }

        return self::DEFAULT_WAREHOUSE;
    }

    private function resolveProductImage(Product $product): ?string
    {
        $photos = is_array($product->photos) ? $product->photos : [];
        foreach ($photos as $photo) {
            if (is_string($photo) && trim($photo) !== '') {
                return trim($photo);
            }

            if (is_array($photo)) {
                $url = trim((string) ($photo['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function composeNote(
        string $reason,
        string $note,
        string $warehouse,
        int $beforeStock,
        int $afterStock
    ): string {
        $parts = [];

        if ($reason !== '') {
            $parts[] = 'Reason: ' . $reason;
        }

        $parts[] = 'Warehouse: ' . $warehouse;

        if ($note !== '') {
            $parts[] = $note;
        }

        $parts[] = sprintf('Stock %d -> %d', $beforeStock, $afterStock);

        return implode(' | ', $parts);
    }
}

