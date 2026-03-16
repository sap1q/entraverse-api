<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncSalesInvoiceToJurnalJob;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Models\UserAddress;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private readonly RajaOngkirService $rajaOngkirService,
        private readonly MidtransService $midtransService
    ) {
    }

    /**
     * @return array{
     *   courier: string,
     *   origin_city_id: string,
     *   destination_city_id: string,
     *   weight: int,
     *   origin: array<string, mixed>,
     *   options: array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}>
     * }
     */
    public function estimateShippingCost(string $destinationCityId, int $weight, string $courier): array
    {
        $normalizedCourier = Str::lower(trim($courier));
        $normalizedWeight = max(1, $weight);
        $normalizedDestination = trim($destinationCityId);
        $origin = $this->rajaOngkirService->getShippingOrigin();

        if (! preg_match('/^\d{4}$/', $normalizedDestination)) {
            throw new RuntimeException('Kota tujuan tidak valid. Perbarui alamat pengiriman terlebih dahulu.');
        }

        $options = $this->rajaOngkirService->getShippingCost(
            destinationCityId: $normalizedDestination,
            weight: $normalizedWeight,
            courier: $normalizedCourier
        );

        return [
            'courier' => $normalizedCourier,
            'origin_city_id' => (string) ($origin['city_id'] ?? ''),
            'destination_city_id' => $normalizedDestination,
            'weight' => $normalizedWeight,
            'origin' => $origin,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   order: SalesOrder,
     *   snap_token: string,
     *   snap_redirect_url: string|null,
     *   shipping: array{service: string, description: ?string, cost: int, etd: ?string, note: ?string},
     *   shipping_weight: int
     * }
     */
    public function processCheckout(User $user, array $payload): array
    {
        $result = DB::transaction(function () use ($user, $payload): array {
            $addressId = trim((string) ($payload['address_id'] ?? ''));
            $address = UserAddress::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereKey($addressId)
                ->first();

            if (! $address) {
                throw ValidationException::withMessages([
                    'address_id' => ['Alamat pengiriman tidak ditemukan.'],
                ]);
            }

            $destinationCityId = trim((string) $address->city_id);
            if (! preg_match('/^\d{4}$/', $destinationCityId)) {
                throw ValidationException::withMessages([
                    'address_id' => ['Alamat pengiriman belum memiliki kota/kabupaten yang valid.'],
                ]);
            }

            $itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            if ($itemsPayload === []) {
                throw ValidationException::withMessages([
                    'items' => ['Item checkout wajib diisi.'],
                ]);
            }

            $prepared = $this->prepareOrderItems($itemsPayload);
            $courier = Str::lower(trim((string) ($payload['courier'] ?? '')));
            $shippingOrigin = $this->rajaOngkirService->getShippingOrigin();
            $shippingServices = $this->rajaOngkirService->getShippingCost(
                destinationCityId: $destinationCityId,
                weight: $prepared['weight'],
                courier: $courier
            );
            $selectedService = $this->resolveShippingService(
                $shippingServices,
                trim((string) ($payload['service'] ?? ''))
            );

            $shippingCost = (float) $selectedService['cost'];
            $subtotal = $prepared['subtotal'];
            $totalAmount = max(0.0, $subtotal + $shippingCost);
            $orderNumber = $this->generateOrderNumber();

            $order = SalesOrder::query()->create([
                'order_number' => $orderNumber,
                'user_id' => (string) $user->id,
                'customer_name' => (string) $address->recipient_name,
                'customer_phone' => $address->recipient_phone,
                'customer_email' => $user->email,
                'customer_address' => $address->full_address ?? $this->composeAddressText($address),
                'status' => 'dibayar',
                'currency' => 'IDR',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount_amount' => 0,
                'total_amount' => $totalAmount,
                'notes' => $payload['notes'] ?? null,
                'payment_method' => 'midtrans_snap',
                'payment_status' => 'pending',
                'payment_reference' => $orderNumber,
                'shipping_courier' => $courier,
                'shipping_service' => $selectedService['service'],
                'shipping_etd' => $selectedService['etd'],
                'shipping_weight' => $prepared['weight'],
                'shipping_destination_city_id' => $destinationCityId,
                'shipping_metadata' => [
                    'origin' => [
                        'source' => $shippingOrigin['source'] ?? null,
                        'label' => $shippingOrigin['label'] ?? null,
                        'city_id' => $shippingOrigin['city_id'] ?? null,
                        'city_name' => $shippingOrigin['city_name'] ?? null,
                        'full_address' => $shippingOrigin['full_address'] ?? null,
                        'recipient_name' => $shippingOrigin['recipient_name'] ?? null,
                        'recipient_phone' => $shippingOrigin['recipient_phone'] ?? null,
                    ],
                    'service_description' => $selectedService['description'],
                    'service_note' => $selectedService['note'],
                ],
            ]);

            foreach ($prepared['items'] as $item) {
                SalesOrderItem::query()->create([
                    'sales_order_id' => (string) $order->id,
                    'product_id' => (string) $item['product_id'],
                    'product_name' => (string) $item['product_name'],
                    'variant_name' => (string) $item['variant_name'],
                    'variant_sku' => (string) $item['variant_sku'],
                    'warehouse' => (string) $item['warehouse'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'landed_cost' => (float) $item['landed_cost'],
                    'line_total' => (float) $item['line_total'],
                    'metadata' => is_array($item['metadata']) ? $item['metadata'] : [],
                ]);
            }

            return [
                'order_id' => (string) $order->id,
                'shipping' => $selectedService,
                'shipping_weight' => $prepared['weight'],
                'items' => $prepared['items'],
            ];
        });

        $order = SalesOrder::query()->with('items')->findOrFail((string) $result['order_id']);
        $snap = $this->midtransService->createSnapToken(
            $this->buildSnapPayload(
                order: $order,
                user: $user,
                items: is_array($result['items']) ? $result['items'] : [],
                shippingService: $result['shipping']
            )
        );

        $order->update([
            'snap_token' => $snap['token'],
            'payment_payload' => [
                'snap' => $snap['raw'],
                'shipping_quote' => $result['shipping'],
            ],
        ]);

        return [
            'order' => $order->fresh(['items']),
            'snap_token' => $snap['token'],
            'snap_redirect_url' => $snap['redirect_url'],
            'shipping' => $result['shipping'],
            'shipping_weight' => $result['shipping_weight'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handlePaymentCallback(array $payload): SalesOrder
    {
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('order_id callback Midtrans tidak valid.');
        }

        $status = Str::lower(trim((string) ($payload['transaction_status'] ?? '')));
        $fraudStatus = Str::lower(trim((string) ($payload['fraud_status'] ?? '')));
        $paymentType = trim((string) ($payload['payment_type'] ?? ''));
        $shouldDeductStock = false;

        $order = DB::transaction(function () use ($orderId, $status, $fraudStatus, $paymentType, $payload, &$shouldDeductStock): SalesOrder {
            $order = SalesOrder::query()
                ->where('order_number', $orderId)
                ->orWhere('payment_reference', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new RuntimeException('Order tidak ditemukan untuk callback Midtrans.');
            }

            $isAlreadySettled = Str::lower((string) $order->payment_status) === 'settlement';
            $isSettlement = $this->isSettlementStatus($status, $fraudStatus);

            $mergedPayload = $this->mergePaymentPayload($order->payment_payload, [
                'callback' => $payload,
            ]);

            $order->payment_method = $paymentType !== '' ? $paymentType : ($order->payment_method ?? 'midtrans_snap');
            $order->payment_payload = $mergedPayload;

            if ($isSettlement) {
                $order->payment_status = 'settlement';
                $order->status = 'diproses';
                $order->settled_at = $this->resolveSettlementDate($payload) ?? now();

                if (! $isAlreadySettled) {
                    $this->deductStockFromSettledOrder($order);
                    $shouldDeductStock = true;
                }
            } elseif ($status === 'pending') {
                if (! $isAlreadySettled) {
                    $order->payment_status = 'pending';
                }
            } else {
                if (! $isAlreadySettled) {
                    $order->payment_status = $status !== '' ? $status : 'failed';
                    $order->status = 'dibatalkan';
                }
            }

            $order->save();

            return $order->fresh(['items']);
        });

        if ($shouldDeductStock) {
            SyncSalesInvoiceToJurnalJob::dispatch((string) $order->id);
        }

        return $order;
    }

    /**
     * @param array<int, mixed> $itemsPayload
     * @return array{
     *   subtotal: float,
     *   weight: int,
     *   items: array<int, array{
     *      product_id: string,
     *      product_name: string,
     *      variant_name: string,
     *      variant_sku: string,
     *      warehouse: string,
     *      quantity: int,
     *      unit_price: float,
     *      landed_cost: float,
     *      line_total: float,
     *      metadata: array<string, mixed>
     *   }>
     * }
     */
    private function prepareOrderItems(array $itemsPayload): array
    {
        $preparedItems = [];
        $subtotal = 0.0;
        $totalWeight = 0;

        foreach ($itemsPayload as $row) {
            if (! is_array($row)) {
                continue;
            }

            $productId = trim((string) ($row['product_id'] ?? ''));
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $variantSku = trim((string) ($row['variant_sku'] ?? ''));
            $selectedVariants = is_array($row['variants'] ?? null) ? $row['variants'] : [];

            /** @var Product|null $product */
            $product = Product::query()
                ->lockForUpdate()
                ->find($productId);

            if (! $product || ! $product->isPubliclyVisible()) {
                throw ValidationException::withMessages([
                    'items' => ["Produk {$productId} tidak ditemukan atau tidak aktif."],
                ]);
            }

            $variant = $this->resolveVariantRow($product, $variantSku, $selectedVariants);
            if ($variant === null) {
                throw ValidationException::withMessages([
                    'items' => ["Varian produk {$product->name} tidak ditemukan."],
                ]);
            }

            $availableStock = (int) ($variant['stock'] ?? 0);
            if ($availableStock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ["Stok tidak cukup untuk {$product->name}."],
                ]);
            }

            $unitPrice = $this->resolveUnitPrice($variant, $product);
            $lineTotal = $unitPrice * $quantity;
            $itemWeight = $this->resolveWeightInGram($variant, $product);
            $warehouse = $this->resolveWarehouse($variant, $product);
            $landedCost = $this->calculateLandedCost($variant);
            $resolvedSku = $this->resolveSku($product, $variant);

            $preparedItems[] = [
                'product_id' => (string) $product->id,
                'product_name' => (string) $product->name,
                'variant_name' => (string) ($variant['label'] ?? $variant['variant_name'] ?? 'Default'),
                'variant_sku' => $resolvedSku,
                'warehouse' => $warehouse,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'landed_cost' => $landedCost,
                'line_total' => $lineTotal,
                'metadata' => [
                    'selected_variants' => $selectedVariants,
                    'item_weight' => $itemWeight,
                    'stock_before_checkout' => $availableStock,
                ],
            ];

            $subtotal += $lineTotal;
            $totalWeight += ($itemWeight * $quantity);
        }

        if ($preparedItems === []) {
            throw ValidationException::withMessages([
                'items' => ['Item checkout tidak valid.'],
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'weight' => max(1, $totalWeight),
            'items' => $preparedItems,
        ];
    }

    /**
     * @param array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}> $services
     * @return array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}
     */
    private function resolveShippingService(array $services, string $requestedService): array
    {
        if ($requestedService === '') {
            $fallback = Arr::first($services);
            if (! is_array($fallback)) {
                throw ValidationException::withMessages([
                    'service' => ['Layanan pengiriman tidak ditemukan.'],
                ]);
            }

            return $fallback;
        }

        $matched = collect($services)->first(
            fn (array $service): bool => strcasecmp((string) $service['service'], $requestedService) === 0
        );

        if (! is_array($matched)) {
            throw ValidationException::withMessages([
                'service' => ['Layanan pengiriman tidak tersedia.'],
            ]);
        }

        return $matched;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{service: string, description: ?string, cost: int, etd: ?string, note: ?string} $shippingService
     * @return array<string, mixed>
     */
    private function buildSnapPayload(
        SalesOrder $order,
        User $user,
        array $items,
        array $shippingService
    ): array {
        $itemDetails = collect($items)
            ->map(function (array $item): array {
                $rawName = trim((string) ($item['product_name'] ?? 'Produk'));
                $variantName = trim((string) ($item['variant_name'] ?? ''));
                $name = trim($variantName !== '' ? "{$rawName} ({$variantName})" : $rawName);

                return [
                    'id' => (string) ($item['variant_sku'] ?? $item['product_id']),
                    'price' => max(1, (int) round((float) ($item['unit_price'] ?? 0))),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'name' => Str::limit($name, 50, ''),
                ];
            })
            ->values()
            ->all();

        $itemDetails[] = [
            'id' => 'SHIPPING',
            'price' => max(0, (int) ($shippingService['cost'] ?? 0)),
            'quantity' => 1,
            'name' => Str::limit('Ongkir ' . (string) ($order->shipping_service ?? 'Reguler'), 50, ''),
        ];

        $appUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $checkoutPath = $appUrl !== '' ? "{$appUrl}/checkout" : null;
        $payload = [
            'transaction_details' => [
                'order_id' => (string) $order->order_number,
                'gross_amount' => max(1, (int) round((float) $order->total_amount)),
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => (string) $order->customer_name,
                'email' => (string) ($order->customer_email ?? $user->email ?? ''),
                'phone' => (string) ($order->customer_phone ?? ''),
            ],
            'metadata' => [
                'sales_order_id' => (string) $order->id,
                'shipping_service' => $order->shipping_service,
                'shipping_courier' => $order->shipping_courier,
            ],
        ];

        if ($checkoutPath) {
            $payload['callbacks'] = [
                'finish' => "{$checkoutPath}?order={$order->order_number}&status=finish",
                'unfinish' => "{$checkoutPath}?order={$order->order_number}&status=unfinish",
                'error' => "{$checkoutPath}?order={$order->order_number}&status=error",
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergePaymentPayload(?array $existing, array $incoming): array
    {
        $base = is_array($existing) ? $existing : [];

        return array_replace_recursive($base, $incoming);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveSettlementDate(array $payload): ?Carbon
    {
        $candidates = [
            $payload['settlement_time'] ?? null,
            $payload['transaction_time'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isSettlementStatus(string $status, string $fraudStatus): bool
    {
        if (! in_array($status, ['settlement', 'capture'], true)) {
            return false;
        }

        if ($fraudStatus === '') {
            return true;
        }

        return $fraudStatus === 'accept';
    }

    private function deductStockFromSettledOrder(SalesOrder $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $product = Product::query()
                ->lockForUpdate()
                ->find($item->product_id);

            if (! $product) {
                throw new RuntimeException("Produk {$item->product_id} tidak ditemukan untuk pengurangan stok.");
            }

            $variantRows = $this->extractVariantRows($product);
            $targetSku = trim((string) $item->variant_sku);
            $targetIndex = null;

            foreach ($variantRows as $index => $variantRow) {
                if (strcasecmp($this->resolveSku($product, $variantRow), $targetSku) === 0) {
                    $targetIndex = $index;
                    break;
                }
            }

            if ($targetIndex === null) {
                throw new RuntimeException("SKU {$targetSku} tidak ditemukan saat settlement.");
            }

            $targetVariant = $variantRows[$targetIndex];
            $warehouse = trim((string) ($item->warehouse ?: $this->resolveWarehouse($targetVariant, $product)));
            $warehouseStock = $this->normalizeWarehouseStock(
                $targetVariant['warehouse_stock'] ?? null,
                $warehouse,
                (int) ($targetVariant['stock'] ?? 0)
            );

            $currentStock = (int) ($warehouseStock[$warehouse] ?? 0);
            if ($currentStock < (int) $item->quantity) {
                throw new RuntimeException("Stok tidak cukup untuk SKU {$targetSku} saat settlement.");
            }

            $warehouseStock[$warehouse] = $currentStock - (int) $item->quantity;
            $targetVariant['warehouse_stock'] = $warehouseStock;
            $targetVariant['stock'] = (int) collect($warehouseStock)->sum();
            $variantRows[$targetIndex] = $targetVariant;

            $updatedTotalStock = (int) collect($variantRows)->sum(fn (array $row): int => (int) ($row['stock'] ?? 0));
            $inventory = is_array($product->inventory) ? $product->inventory : [];
            $inventory['total_stock'] = $updatedTotalStock;

            $product->variant_pricing = array_values($variantRows);
            $product->inventory = $inventory;
            $product->stock = $updatedTotalStock;
            $product->stock_status = $updatedTotalStock > 0 ? 'in_stock' : 'out_of_stock';
            $product->save();
        }
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function resolveWarehouse(array $variant, Product $product): string
    {
        $candidate = trim((string) ($variant['warehouse'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $inventory = is_array($product->inventory) ? $product->inventory : [];
        $fallback = trim((string) ($inventory['warehouse'] ?? ''));

        return $fallback !== '' ? $fallback : 'Gudang Utama';
    }

    private function resolveUnitPrice(array $variant, Product $product): float
    {
        $candidates = [
            (float) ($variant['entraverse_price'] ?? 0),
            (float) ($variant['offline_price'] ?? 0),
            (float) ($variant['price'] ?? 0),
            (float) Arr::get($product->inventory, 'price', 0),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 0.0;
    }

    private function calculateLandedCost(array $variant): float
    {
        $purchasePriceIdr = (float) ($variant['purchase_price_idr'] ?? 0);
        if ($purchasePriceIdr > 0) {
            return $purchasePriceIdr;
        }

        $purchasePrice = (float) ($variant['purchase_price'] ?? 0);
        $exchangeRate = (float) ($variant['exchange_rate'] ?? $variant['exchange_value'] ?? 0);
        $arrivalCost = (float) ($variant['arrival_cost'] ?? 0);
        $currency = strtoupper(trim((string) ($variant['currency'] ?? '')));
        $currencySurcharge = in_array($currency, ['USD', 'SGD'], true) ? 50.0 : 0.0;
        $adjustedExchangeRate = $exchangeRate + $currencySurcharge;

        return max(0.0, ($purchasePrice * $adjustedExchangeRate) + $arrivalCost);
    }

    private function resolveWeightInGram(array $variant, Product $product): int
    {
        $candidates = [
            (float) ($variant['item_weight'] ?? 0),
            (float) Arr::get($variant, 'weight', 0),
            (float) Arr::get($product->inventory, 'weight', 0),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return (int) max(1, round($candidate));
            }
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function resolveSku(Product $product, array $variant): string
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
            return $spu;
        }

        return sprintf('SKU-%s', (string) $product->id);
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, string>
     */
    private function normalizeVariantOptionMap(array $variant): array
    {
        $options = $variant['options'] ?? $variant['variant_options'] ?? [];
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }

            $normalizedName = Str::lower(trim($name));
            $normalizedValue = Str::lower(trim($value));
            if ($normalizedName === '' || $normalizedValue === '') {
                continue;
            }

            $normalized[$normalizedName] = $normalizedValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $selectedVariants
     */
    private function resolveVariantRow(Product $product, string $variantSku, array $selectedVariants): ?array
    {
        $variantRows = $this->extractVariantRows($product);
        if ($variantRows === []) {
            return null;
        }

        if ($variantSku !== '') {
            foreach ($variantRows as $variant) {
                if (strcasecmp($this->resolveSku($product, $variant), $variantSku) === 0) {
                    return $variant;
                }
            }
        }

        $normalizedSelection = [];
        foreach ($selectedVariants as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }

            $normalizedName = Str::lower(trim($name));
            $normalizedValue = Str::lower(trim($value));
            if ($normalizedName === '' || $normalizedValue === '') {
                continue;
            }

            $normalizedSelection[$normalizedName] = $normalizedValue;
        }

        if ($normalizedSelection !== []) {
            foreach ($variantRows as $variant) {
                $options = $this->normalizeVariantOptionMap($variant);
                if ($options === []) {
                    continue;
                }

                $matches = true;
                foreach ($normalizedSelection as $name => $value) {
                    if (($options[$name] ?? null) !== $value) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    return $variant;
                }
            }
        }

        return $variantRows[0];
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
                'warehouse_stock' => [
                    ($inventory['warehouse'] ?? 'Gudang Utama') => (int) ($inventory['total_stock'] ?? $product->stock ?? 0),
                ],
            ];
        }

        return array_values(array_map(function (array $row) use ($product): array {
            $normalized = $row;
            $warehouse = $this->resolveWarehouse($normalized, $product);
            $normalized['warehouse'] = $warehouse;
            $normalized['warehouse_stock'] = $this->normalizeWarehouseStock(
                $normalized['warehouse_stock'] ?? null,
                $warehouse,
                (int) ($normalized['stock'] ?? 0)
            );
            $normalized['stock'] = (int) collect($normalized['warehouse_stock'])->sum();
            $normalized['label'] = (string) ($normalized['label'] ?? $normalized['variant_name'] ?? 'Default');

            return $normalized;
        }, $rows));
    }

    /**
     * @return array<string, int>
     */
    private function normalizeWarehouseStock(
        mixed $warehouseStock,
        string $fallbackWarehouse,
        int $fallbackStock
    ): array {
        $normalized = [];

        if (is_array($warehouseStock)) {
            foreach ($warehouseStock as $warehouse => $qty) {
                if (! is_string($warehouse)) {
                    continue;
                }

                $warehouseName = trim($warehouse);
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

    private function generateOrderNumber(): string
    {
        do {
            $candidate = sprintf('SO-%s-%04d', now()->format('Ymd'), random_int(0, 9999));
        } while (SalesOrder::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function composeAddressText(UserAddress $address): string
    {
        $segments = [
            $address->address_detail,
            $address->subdistrict,
            $address->district?->name,
            $address->city?->name,
            $address->province?->name,
            $address->zip_code,
        ];

        return implode(', ', array_filter($segments, fn ($segment): bool => is_string($segment) && trim($segment) !== ''));
    }
}
