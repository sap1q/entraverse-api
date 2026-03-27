<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            ->with(['items.product:id,photos', 'creator:id,name,email', 'invoice'])
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

            return $order->load(['items.product:id,photos', 'creator:id,name,email', 'invoice']);
        });
    }

    public function find(string $orderId): SalesOrder
    {
        return SalesOrder::query()
            ->with(['items.product:id,photos', 'creator:id,name,email', 'invoice'])
            ->findOrFail($orderId);
    }

    public function updateFulfillment(Admin $admin, string $orderId, array $payload): SalesOrder
    {
        return DB::transaction(function () use ($admin, $orderId, $payload): SalesOrder {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()
                ->with(['items.product:id,photos', 'creator:id,name,email', 'invoice'])
                ->findOrFail($orderId);

            $action = Str::lower(trim((string) ($payload['action'] ?? '')));

            return match ($action) {
                'confirm' => $this->confirmOrder($order, $admin),
                'ship' => $this->shipOrder($order, $admin, $payload),
                'cancel' => $this->cancelOrder($order, $admin),
                default => throw ValidationException::withMessages([
                    'action' => ['Aksi pemenuhan pesanan tidak dikenali.'],
                ]),
            };
        });
    }

    public function updateStatus(Admin $admin, string $orderId, string $nextStatus): SalesOrder
    {
        return DB::transaction(function () use ($admin, $orderId, $nextStatus): SalesOrder {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()
                ->with(['items.product:id,photos', 'creator:id,name,email', 'invoice'])
                ->findOrFail($orderId);

            $normalized = Str::lower(trim($nextStatus));

            if ($normalized === 'pending') {
                $order->status = 'dibayar';
                $order->payment_status = 'pending';
                $order->settled_at = null;
                $order->updated_by = (string) $admin->id;
                $order->save();

                if ($order->invoice) {
                    $order->invoice->payment_status = 'pending';
                    $order->invoice->paid_at = null;
                    $order->invoice->save();
                }

                return $this->refreshOrder($order);
            }

            if ($normalized === 'paid') {
                $this->markOrderAsPaid($order);
                $order->status = 'dibayar';
                $order->updated_by = (string) $admin->id;
                $order->save();

                return $this->refreshOrder($order);
            }

            if (in_array($normalized, ['diproses', 'dikirim', 'terkirim', 'selesai'], true)) {
                $this->markOrderAsPaid($order);
                $order->status = $normalized;
                $order->updated_by = (string) $admin->id;
                $order->save();

                return $this->refreshOrder($order);
            }

            if ($normalized === 'dibatalkan') {
                $order->status = 'dibatalkan';
                if (! $this->hasSuccessfulPayment($order)) {
                    $order->payment_status = 'cancel';
                }
                $order->updated_by = (string) $admin->id;
                $order->save();

                if ($order->invoice && (string) $order->invoice->payment_status !== 'paid') {
                    $order->invoice->payment_status = 'failed';
                    $order->invoice->save();
                }

                return $this->refreshOrder($order);
            }

            throw ValidationException::withMessages([
                'status' => ['Status pesanan tidak dikenali.'],
            ]);
        });
    }

    public function syncTrackingStatus(array $payload): SalesOrder
    {
        return DB::transaction(function () use ($payload): SalesOrder {
            $orderNumber = trim((string) ($payload['order_number'] ?? ''));
            $trackingNumber = trim((string) ($payload['tracking_number'] ?? $payload['resi_number'] ?? $payload['waybill'] ?? ''));
            $trackingUrl = trim((string) ($payload['tracking_url'] ?? ''));
            $courier = trim((string) ($payload['shipping_courier'] ?? $payload['courier'] ?? ''));
            $rawStatus = trim((string) ($payload['status'] ?? $payload['tracking_status'] ?? $payload['delivery_status'] ?? ''));

            if ($rawStatus === '') {
                throw ValidationException::withMessages([
                    'status' => ['Status tracking vendor wajib diisi.'],
                ]);
            }

            if ($orderNumber === '' && $trackingNumber === '') {
                throw ValidationException::withMessages([
                    'tracking_number' => ['order_number atau tracking_number wajib dikirim oleh vendor.'],
                ]);
            }

            $query = SalesOrder::query()
                ->with(['items.product:id,photos', 'creator:id,name,email', 'invoice']);

            if ($orderNumber !== '') {
                $query->where('order_number', $orderNumber);
            }

            if ($trackingNumber !== '') {
                $query->when(
                    $orderNumber !== '',
                    fn (Builder $builder) => $builder->orWhere('shipping_metadata->tracking_number', $trackingNumber),
                    fn (Builder $builder) => $builder->where('shipping_metadata->tracking_number', $trackingNumber)
                );
            }

            /** @var SalesOrder|null $order */
            $order = $query->lockForUpdate()->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'order' => ['Pesanan untuk update tracking vendor tidak ditemukan.'],
                ]);
            }

            $normalizedStatus = $this->normalizeVendorTrackingStatus($rawStatus);
            $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];

            if ($trackingNumber !== '') {
                $metadata['tracking_number'] = $trackingNumber;
            }

            if ($trackingUrl !== '') {
                $metadata['tracking_url'] = $trackingUrl;
            }

            if ($courier !== '') {
                $order->shipping_courier = $courier;
            }

            $metadata['tracking_status'] = $normalizedStatus;
            $metadata['vendor_tracking_status'] = $rawStatus;
            $metadata['tracking_synced_at'] = now()->toISOString();
            $metadata['tracking_last_source'] = 'vendor_webhook';

            if ($normalizedStatus === 'delivered') {
                $metadata['delivered_at'] = $this->resolveTrackingDate($payload['delivered_at'] ?? $payload['updated_at'] ?? null)
                    ?? now()->toISOString();

                if (! in_array((string) $order->status, ['selesai', 'dibatalkan'], true)) {
                    $order->status = 'terkirim';
                }
            }

            $order->shipping_metadata = $metadata;
            $order->save();

            return $this->refreshOrder($order);
        });
    }

    public function delete(string $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()
                ->with(['invoice'])
                ->findOrFail($orderId);

            $lockedStatuses = ['diproses', 'dikirim', 'terkirim', 'selesai'];
            if (in_array((string) $order->status, $lockedStatuses, true)) {
                throw ValidationException::withMessages([
                    'order' => ['Pesanan yang sudah diproses atau dikirim tidak dapat dihapus.'],
                ]);
            }

            $successfulPaymentStatuses = ['settlement', 'capture', 'paid'];
            if (in_array(strtolower((string) $order->payment_status), $successfulPaymentStatuses, true)) {
                throw ValidationException::withMessages([
                    'order' => ['Pesanan dengan pembayaran berhasil tidak dapat dihapus.'],
                ]);
            }

            if ($order->invoice && (string) $order->invoice->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'order' => ['Invoice yang sudah lunas tidak dapat dihapus.'],
                ]);
            }

            $order->delete();
        });
    }

    private function confirmOrder(SalesOrder $order, Admin $admin): SalesOrder
    {
        if (! $this->hasSuccessfulPayment($order)) {
            throw ValidationException::withMessages([
                'order' => ['Pesanan belum memiliki pembayaran yang berhasil untuk dikonfirmasi.'],
            ]);
        }

        if ((string) $order->status !== 'dibayar') {
            throw ValidationException::withMessages([
                'order' => ['Hanya pesanan dengan status dibayar yang dapat dikonfirmasi ke proses fulfillment.'],
            ]);
        }

        $order->status = 'diproses';
        $order->updated_by = (string) $admin->id;
        $order->save();

        return $this->refreshOrder($order);
    }

    private function shipOrder(SalesOrder $order, Admin $admin, array $payload): SalesOrder
    {
        if (! $this->hasSuccessfulPayment($order)) {
            throw ValidationException::withMessages([
                'order' => ['Pesanan belum lunas sehingga belum dapat diberi resi pengiriman.'],
            ]);
        }

        if (! in_array((string) $order->status, ['diproses', 'dikirim'], true)) {
            throw ValidationException::withMessages([
                'order' => ['Resi hanya dapat diinput untuk pesanan yang sedang diproses atau sudah dikirim.'],
            ]);
        }

        $trackingNumber = trim((string) ($payload['tracking_number'] ?? ''));
        $trackingUrl = trim((string) ($payload['tracking_url'] ?? ''));
        $courier = trim((string) ($payload['shipping_courier'] ?? ''));
        $service = trim((string) ($payload['shipping_service'] ?? ''));
        $adminNote = trim((string) ($payload['note'] ?? ''));

        $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];
        $metadata['tracking_number'] = $trackingNumber;
        $metadata['shipped_at'] = now()->toISOString();
        $metadata['shipped_by'] = (string) $admin->id;

        if ($trackingUrl !== '') {
            $metadata['tracking_url'] = $trackingUrl;
        } else {
            unset($metadata['tracking_url']);
        }

        if ($adminNote !== '') {
            $metadata['admin_note'] = $adminNote;
        }

        if ($courier !== '') {
            $order->shipping_courier = $courier;
        }

        if ($service !== '') {
            $order->shipping_service = $service;
        }

        $order->status = 'dikirim';
        $order->shipping_metadata = $metadata;
        $order->updated_by = (string) $admin->id;
        $order->save();

        return $this->refreshOrder($order);
    }

    private function cancelOrder(SalesOrder $order, Admin $admin): SalesOrder
    {
        if ($this->hasSuccessfulPayment($order)) {
            throw ValidationException::withMessages([
                'order' => ['Pesanan yang sudah dibayar tidak dapat dibatalkan dari daftar ini. Gunakan alur refund terpisah.'],
            ]);
        }

        if (in_array((string) $order->status, ['dikirim', 'terkirim', 'selesai', 'dibatalkan'], true)) {
            throw ValidationException::withMessages([
                'order' => ['Status pesanan saat ini tidak dapat dibatalkan.'],
            ]);
        }

        $order->status = 'dibatalkan';
        $order->payment_status = 'cancel';
        $order->updated_by = (string) $admin->id;
        $order->save();

        if ($order->invoice && (string) $order->invoice->payment_status !== 'paid') {
            $order->invoice->payment_status = 'failed';
            $order->invoice->save();
        }

        return $this->refreshOrder($order);
    }

    private function hasSuccessfulPayment(SalesOrder $order): bool
    {
        $candidates = [
            Str::lower(trim((string) ($order->payment_status ?? ''))),
            Str::lower(trim((string) ($order->invoice?->payment_status ?? ''))),
        ];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, ['settlement', 'capture', 'paid'], true)) {
                return true;
            }
        }

        return false;
    }

    private function markOrderAsPaid(SalesOrder $order): void
    {
        $order->payment_status = 'settlement';
        if ($order->settled_at === null) {
            $order->settled_at = now();
        }

        if ($order->invoice) {
            $order->invoice->payment_status = 'paid';
            if ($order->invoice->paid_at === null) {
                $order->invoice->paid_at = now();
            }
            $order->invoice->save();
        }
    }

    private function normalizeVendorTrackingStatus(string $status): string
    {
        $normalized = Str::of($status)
            ->lower()
            ->replace(['-', '_'], ' ')
            ->squish()
            ->value();

        if (in_array($normalized, [
            'delivered',
            'terkirim',
            'received',
            'completed',
            'success',
            'pod',
            'proof of delivery',
            'delivered to customer',
            'package delivered',
        ], true)) {
            return 'delivered';
        }

        if (in_array($normalized, [
            'shipped',
            'in transit',
            'on delivery',
            'out for delivery',
            'delivery process',
        ], true)) {
            return 'in_transit';
        }

        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function resolveTrackingDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function refreshOrder(SalesOrder $order): SalesOrder
    {
        /** @var SalesOrder|null $fresh */
        $fresh = $order->fresh(['items.product:id,photos', 'creator:id,name,email', 'invoice']);

        return $fresh ?? $order->load(['items.product:id,photos', 'creator:id,name,email', 'invoice']);
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
        $exchangeValue = (float) ($variant['exchange_value'] ?? $variant['exchange_rate'] ?? 0);
        $arrivalCost = (float) ($variant['arrival_cost'] ?? 0);
        $currency = strtoupper(trim((string) ($variant['currency'] ?? '')));
        $currencySurcharge = in_array($currency, ['USD', 'SGD'], true) ? 50.0 : 0.0;
        $adjustedExchangeRate = $exchangeValue + $currencySurcharge;

        return max(0.0, ($purchasePrice * $adjustedExchangeRate) + $arrivalCost);
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
