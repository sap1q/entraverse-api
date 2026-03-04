<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));
        $driver = DB::connection()->getDriverName();

        return SalesOrder::query()
            ->with(['items', 'creator:id,name,email'])
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(fn (Builder $q) => $q
                        ->where('order_number', 'ilike', "%{$search}%")
                        ->orWhere('customer_name', 'ilike', "%{$search}%"));
                    return;
                }

                $keyword = '%' . strtolower($search) . '%';
                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(order_number) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', [$keyword]));
            })
            ->latest()
            ->paginate($perPage)
            ->appends($filters);
    }

    public function catalog(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $driver = DB::connection()->getDriverName();

        $products = Product::query()
            ->select(['id', 'name', 'spu', 'variant_pricing'])
            ->when($search !== '', function (Builder $query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(fn (Builder $q) => $q
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('spu', 'ilike', "%{$search}%"));
                    return;
                }

                $keyword = '%' . strtolower($search) . '%';
                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(spu) LIKE ?', [$keyword]));
            })
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return $products->flatMap(function (Product $product): array {
            $variants = $this->extractVariantRows($product);
            $result = [];

            foreach ($variants as $index => $variant) {
                $warehouseStock = $this->normalizeWarehouseStock(
                    $variant['warehouse_stock'] ?? null,
                    (string) ($variant['warehouse'] ?? 'Gudang Utama'),
                    (int) ($variant['stock'] ?? 0)
                );

                $landedCost = $this->calculateLandedCost($variant);
                $unitPrice = $this->resolveUnitPrice($variant, $landedCost);

                $result[] = [
                    'id' => sprintf('%s:%d', (string) $product->id, $index),
                    'product_id' => (string) $product->id,
                    'product_name' => (string) $product->name,
                    'product_spu' => (string) ($product->spu ?? ''),
                    'variant_sku' => (string) $this->resolveSku($product, $variant, $index),
                    'variant_name' => (string) ($variant['label'] ?? $variant['variant_name'] ?? 'Default'),
                    'warehouse_stock' => collect($warehouseStock)->map(
                        fn (int $stock, string $warehouse): array => [
                            'warehouse' => $warehouse,
                            'stock' => $stock,
                        ]
                    )->values()->all(),
                    'available_stock' => (int) collect($warehouseStock)->sum(),
                    'landed_cost' => $landedCost,
                    'unit_price' => $unitPrice,
                ];
            }

            return $result;
        })->values()->all();
    }

    public function create(Admin $admin, array $payload): SalesOrder
    {
        return DB::transaction(function () use ($admin, $payload): SalesOrder {
            $orderNumber = $this->generateOrderNumber();
            $shippingCost = (float) ($payload['shipping_cost'] ?? 0);
            $discountAmount = (float) ($payload['discount_amount'] ?? 0);
            $itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            if ($itemsPayload === []) {
                throw ValidationException::withMessages([
                    'items' => ['Setidaknya satu item pesanan wajib diisi.'],
                ]);
            }

            $preparedItems = [];
            $subtotal = 0.0;

            foreach ($itemsPayload as $item) {
                $productId = (string) ($item['product_id'] ?? '');
                $variantSku = trim((string) ($item['variant_sku'] ?? ''));
                $warehouse = trim((string) ($item['warehouse'] ?? ''));
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                /** @var Product $product */
                $product = Product::query()->findOrFail($productId);
                $variant = $this->findVariantBySku($product, $variantSku);

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        'items' => ["SKU {$variantSku} tidak ditemukan pada produk {$product->name}."],
                    ]);
                }

                $warehouseStock = $this->normalizeWarehouseStock(
                    $variant['warehouse_stock'] ?? null,
                    (string) ($variant['warehouse'] ?? 'Gudang Utama'),
                    (int) ($variant['stock'] ?? 0)
                );
                $availableAtWarehouse = (int) ($warehouseStock[$warehouse] ?? 0);

                if ($warehouse === '') {
                    throw ValidationException::withMessages([
                        'items' => ["Warehouse wajib dipilih untuk SKU {$variantSku}."],
                    ]);
                }

                if (($payload['status'] ?? '') === 'diproses' && $availableAtWarehouse < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Stok tidak cukup untuk SKU {$variantSku} di warehouse {$warehouse}."],
                    ]);
                }

                $landedCost = $this->calculateLandedCost($variant);
                $defaultUnitPrice = $this->resolveUnitPrice($variant, $landedCost);
                $unitPrice = isset($item['unit_price']) ? max(0.0, (float) $item['unit_price']) : $defaultUnitPrice;
                $lineTotal = $unitPrice * $quantity;

                $preparedItems[] = [
                    'product_id' => (string) $product->id,
                    'product_name' => (string) $product->name,
                    'variant_name' => (string) ($variant['label'] ?? $variant['variant_name'] ?? 'Default'),
                    'variant_sku' => $variantSku,
                    'warehouse' => $warehouse,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'landed_cost' => $landedCost,
                    'line_total' => $lineTotal,
                    'metadata' => [
                        'warehouse_available_before' => $availableAtWarehouse,
                        'variant_total_stock_before' => (int) collect($warehouseStock)->sum(),
                    ],
                ];

                $subtotal += $lineTotal;
            }

            $totalAmount = max(0.0, $subtotal + $shippingCost - $discountAmount);

            $order = SalesOrder::query()->create([
                'order_number' => $orderNumber,
                'customer_name' => (string) $payload['customer_name'],
                'customer_phone' => $payload['customer_phone'] ?? null,
                'customer_email' => $payload['customer_email'] ?? null,
                'customer_address' => $payload['customer_address'] ?? null,
                'status' => (string) $payload['status'],
                'currency' => 'IDR',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'notes' => $payload['notes'] ?? null,
                'created_by' => (string) $admin->id,
                'updated_by' => (string) $admin->id,
            ]);

            foreach ($preparedItems as $itemData) {
                SalesOrderItem::query()->create([
                    'sales_order_id' => (string) $order->id,
                    ...$itemData,
                ]);
            }

            if ((string) $order->status === 'diproses') {
                foreach ($preparedItems as $itemData) {
                    $this->stockService->adjust($admin, [
                        'product_id' => (string) $itemData['product_id'],
                        'variant_sku' => (string) $itemData['variant_sku'],
                        'warehouse' => (string) $itemData['warehouse'],
                        'type' => 'out',
                        'quantity' => (int) $itemData['quantity'],
                        'reason' => 'sale',
                        'direction' => 'decrement',
                        'reference' => 'order:' . $orderNumber,
                        'note' => 'Auto stock out dari Sales Order ' . $orderNumber,
                        'allow_negative' => false,
                    ]);
                }
            }

            return $order->load(['items', 'creator:id,name,email']);
        });
    }

    public function find(string $orderId): SalesOrder
    {
        return SalesOrder::query()
            ->with(['items', 'creator:id,name,email'])
            ->findOrFail($orderId);
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
                'warehouse' => $inventory['warehouse'] ?? 'Gudang Utama',
            ];
        }

        return array_values(array_map(function (array $row, int $index) use ($product): array {
            $normalized = $row;
            $normalized['sku'] = $this->resolveSku($product, $row, $index);
            $normalized['label'] = (string) ($row['label'] ?? $row['variant_name'] ?? $row['variant_code'] ?? 'Default');
            $normalized['warehouse_stock'] = $this->normalizeWarehouseStock(
                $row['warehouse_stock'] ?? null,
                (string) ($row['warehouse'] ?? 'Gudang Utama'),
                (int) ($row['stock'] ?? 0),
            );
            $normalized['stock'] = (int) collect($normalized['warehouse_stock'])->sum();

            return $normalized;
        }, $rows, array_keys($rows)));
    }

    private function findVariantBySku(Product $product, string $variantSku): ?array
    {
        $variants = $this->extractVariantRows($product);
        foreach ($variants as $index => $variant) {
            $sku = $this->resolveSku($product, $variant, $index);
            if (strcasecmp($sku, $variantSku) === 0) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
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
            $warehouse = trim($fallbackWarehouse) !== '' ? trim($fallbackWarehouse) : 'Gudang Utama';
            $normalized[$warehouse] = max(0, $fallbackStock);
        }

        return $normalized;
    }

    private function calculateLandedCost(array $variant): float
    {
        $purchasePriceIdr = (float) ($variant['purchase_price_idr'] ?? 0);
        if ($purchasePriceIdr > 0) {
            return $purchasePriceIdr;
        }

        $purchasePrice = (float) ($variant['purchase_price'] ?? 0);
        $exchangeValue = (float) ($variant['exchange_value'] ?? 0);
        $arrivalCost = (float) ($variant['arrival_cost'] ?? 0);
        $shippingCost = (float) ($variant['shipping_cost'] ?? 0);

        return max(0.0, ($purchasePrice * $exchangeValue) + $arrivalCost + $shippingCost);
    }

    private function resolveUnitPrice(array $variant, float $landedCost): float
    {
        $candidates = [
            (float) ($variant['entraverse_price'] ?? 0),
            (float) ($variant['offline_price'] ?? 0),
            (float) ($variant['price'] ?? 0),
            $landedCost,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 0.0;
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

    private function generateOrderNumber(): string
    {
        do {
            $candidate = sprintf('SO-%s-%04d', now()->format('Ymd'), random_int(0, 9999));
        } while (SalesOrder::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }
}

