<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Jobs\SyncSalesInvoiceToJurnalJob;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMutation;
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
     *   destination_district_id: ?string,
     *   item_weight: int,
     *   packaging_weight: int,
     *   weight: int,
     *   strict_mode: bool,
     *   origin: array<string, mixed>,
     *   options: array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}>
     * }
     */
    public function estimateShippingCost(
        User $user,
        string $courier,
        ?string $addressId = null,
        ?string $destinationCityId = null,
        ?string $destinationDistrictId = null,
        ?int $weight = null,
        array $itemsPayload = []
    ): array
    {
        $normalizedCourier = Str::lower(trim($courier));
        $origin = $this->rajaOngkirService->getShippingOrigin();
        $prepared = $itemsPayload !== [] ? $this->prepareOrderItems($itemsPayload, lockProducts: false) : null;
        $destination = $this->resolveShippingDestination(
            user: $user,
            addressId: $addressId,
            cityId: $destinationCityId,
            districtId: $destinationDistrictId
        );

        if ($prepared !== null) {
            $itemWeight = max(1, (int) ($prepared['item_weight'] ?? 0));
            $packagingWeight = max(0, (int) ($prepared['packaging_weight'] ?? 0));
            $normalizedWeight = max(1, (int) ($prepared['weight'] ?? 0));
        } else {
            $itemWeight = max(0, (int) ($weight ?? 0));
            if ($itemWeight < 1) {
                throw ValidationException::withMessages([
                    'weight' => ['Berat pengiriman wajib diisi atau kirim daftar item checkout.'],
                ]);
            }

            $packagingWeight = $this->resolvePackagingWeightInGram();
            $normalizedWeight = max(1, $itemWeight + $packagingWeight);
        }

        $options = $this->rajaOngkirService->getShippingCost(
            destinationCityId: $destination['city_id'],
            weight: $normalizedWeight,
            courier: $normalizedCourier,
            destinationDistrictId: $destination['district_id']
        );

        return [
            'courier' => $normalizedCourier,
            'origin_city_id' => (string) ($origin['city_id'] ?? ''),
            'destination_city_id' => $destination['city_id'],
            'destination_district_id' => $destination['district_id'],
            'item_weight' => $itemWeight,
            'packaging_weight' => $packagingWeight,
            'weight' => $normalizedWeight,
            'strict_mode' => $this->isStrictShippingMode(),
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

            $shippingDestination = $this->resolveShippingDestinationFromAddress($address);
            $destinationCityId = $shippingDestination['city_id'];
            $destinationDistrictId = $shippingDestination['district_id'];

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
                courier: $courier,
                destinationDistrictId: $destinationDistrictId
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
                    'destination' => [
                        'city_id' => $destinationCityId,
                        'district_id' => $destinationDistrictId,
                    ],
                    'item_weight' => $prepared['item_weight'],
                    'packaging_weight' => $prepared['packaging_weight'],
                    'strict_mode' => $this->isStrictShippingMode(),
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

            Invoice::query()->create([
                'order_id' => (string) $order->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'payment_method' => 'Midtrans Snap',
                'amount_total' => $totalAmount,
                'payment_status' => 'pending',
            ]);

            $this->deductStockWhenOrderCreated($order, $prepared['items']);

            return [
                'order_id' => (string) $order->id,
                'shipping' => $selectedService,
                'item_weight' => $prepared['item_weight'],
                'packaging_weight' => $prepared['packaging_weight'],
                'shipping_weight' => $prepared['weight'],
                'items' => $prepared['items'],
            ];
        });

        $order = SalesOrder::query()->with(['items', 'invoice'])->findOrFail((string) $result['order_id']);
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

        $this->syncInvoiceFromOrder($order->fresh(), [
            'payment_type' => 'midtrans_snap',
        ]);

        return [
            'order' => $order->fresh(['items.product', 'invoice']),
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
                $order->settled_at = $this->resolveSettlementDate($payload) ?? now();

                if (! in_array((string) $order->status, ['diproses', 'dikirim', 'terkirim', 'selesai'], true)) {
                    $order->status = 'dibayar';
                }

                if (! $isAlreadySettled && ! $this->hasDeductedStock($order)) {
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
            $this->syncInvoiceFromOrder($order, $payload);

            return $order->fresh(['items.product', 'invoice']);
        });

        if ($shouldDeductStock) {
            SyncSalesInvoiceToJurnalJob::dispatch((string) $order->id);
        }

        return $order;
    }

    public function syncPendingPaymentStatus(SalesOrder $order): SalesOrder
    {
        $order->loadMissing(['items.product', 'invoice']);

        $currentPaymentStatus = Str::lower(trim((string) ($order->payment_status ?? 'pending')));
        if (! in_array($currentPaymentStatus, ['pending', 'unfinish', 'challenge'], true)) {
            return $order->fresh(['items.product', 'invoice']) ?? $order;
        }

        $lookupOrderId = trim((string) ($order->order_number ?: $order->payment_reference ?: ''));
        if ($lookupOrderId === '') {
            return $order->fresh(['items.product', 'invoice']) ?? $order;
        }

        $payload = $this->midtransService->getTransactionStatus($lookupOrderId);
        if (! is_array($payload) || trim((string) ($payload['transaction_status'] ?? '')) === '') {
            return $order->fresh(['items.product', 'invoice']) ?? $order;
        }

        if (trim((string) ($payload['order_id'] ?? '')) === '') {
            $payload['order_id'] = $lookupOrderId;
        }

        return $this->handlePaymentCallback($payload);
    }

    /**
     * @return array{city_id: string, district_id: ?string}
     */
    private function resolveShippingDestination(
        User $user,
        ?string $addressId = null,
        ?string $cityId = null,
        ?string $districtId = null
    ): array {
        $normalizedAddressId = trim((string) ($addressId ?? ''));
        if ($normalizedAddressId !== '') {
            $address = UserAddress::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereKey($normalizedAddressId)
                ->first();

            if (! $address) {
                throw ValidationException::withMessages([
                    'address_id' => ['Alamat pengiriman tidak ditemukan.'],
                ]);
            }

            return $this->resolveShippingDestinationFromAddress($address);
        }

        $normalizedCityId = trim((string) ($cityId ?? ''));
        if (! preg_match('/^\d{4}$/', $normalizedCityId)) {
            throw ValidationException::withMessages([
                'city_id' => ['Kota tujuan tidak valid. Perbarui alamat pengiriman terlebih dahulu.'],
            ]);
        }

        $normalizedDistrictId = trim((string) ($districtId ?? ''));
        if ($this->isStrictShippingMode() && $normalizedDistrictId === '') {
            throw ValidationException::withMessages([
                'district_id' => ['Kecamatan tujuan wajib dipilih untuk menghitung ongkir.'],
            ]);
        }

        if ($normalizedDistrictId !== '' && ! preg_match('/^\d{7}$/', $normalizedDistrictId)) {
            throw ValidationException::withMessages([
                'district_id' => ['Kecamatan tujuan tidak valid.'],
            ]);
        }

        return [
            'city_id' => $normalizedCityId,
            'district_id' => $normalizedDistrictId !== '' ? $normalizedDistrictId : null,
        ];
    }

    /**
     * @return array{city_id: string, district_id: ?string}
     */
    private function resolveShippingDestinationFromAddress(UserAddress $address): array
    {
        $destinationCityId = trim((string) $address->city_id);
        if (! preg_match('/^\d{4}$/', $destinationCityId)) {
            throw ValidationException::withMessages([
                'address_id' => ['Alamat pengiriman belum memiliki kota/kabupaten yang valid.'],
            ]);
        }

        $destinationDistrictId = trim((string) ($address->district_id ?? ''));
        if ($this->isStrictShippingMode() && $destinationDistrictId === '') {
            throw ValidationException::withMessages([
                'address_id' => ['Alamat pengiriman belum memiliki kecamatan. Lengkapi alamat untuk melihat ongkir.'],
            ]);
        }

        if ($destinationDistrictId !== '' && ! preg_match('/^\d{7}$/', $destinationDistrictId)) {
            throw ValidationException::withMessages([
                'address_id' => ['Alamat pengiriman belum memiliki kecamatan RajaOngkir yang valid.'],
            ]);
        }

        return [
            'city_id' => $destinationCityId,
            'district_id' => $destinationDistrictId !== '' ? $destinationDistrictId : null,
        ];
    }

    /**
     * @param array<int, mixed> $itemsPayload
     * @return array{
     *   subtotal: float,
     *   item_weight: int,
     *   packaging_weight: int,
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
    private function prepareOrderItems(array $itemsPayload, bool $lockProducts = true): array
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
            $productQuery = Product::query();
            if ($lockProducts) {
                $productQuery->lockForUpdate();
            }

            $product = $productQuery->find($productId);

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
            if ($itemWeight === null) {
                throw ValidationException::withMessages([
                    'items' => [sprintf(
                        'Berat produk %s belum dikonfigurasi. Lengkapi berat produk sebelum menghitung ongkir.',
                        $this->describeCheckoutItem($product, $variant)
                    )],
                ]);
            }
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

        $packagingWeight = $this->resolvePackagingWeightInGram();

        return [
            'subtotal' => $subtotal,
            'item_weight' => max(1, $totalWeight),
            'packaging_weight' => $packagingWeight,
            'weight' => max(1, $totalWeight + $packagingWeight),
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
        $transactionsPath = $appUrl !== '' ? "{$appUrl}/transaksi" : null;
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

        if ($transactionsPath) {
            $queryBase = http_build_query([
                'highlight' => (string) $order->id,
                'invoice' => (string) $order->order_number,
            ]);

            $payload['callbacks'] = [
                'finish' => "{$transactionsPath}?{$queryBase}&payment_status=finish",
                'unfinish' => "{$transactionsPath}?{$queryBase}&payment_status=unfinish",
                'error' => "{$transactionsPath}?{$queryBase}&payment_status=error",
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

        $this->markStockAsDeducted($order, 'payment_settlement');
    }

    /**
     * @param array<int, array<string, mixed>> $preparedItems
     */
    private function deductStockWhenOrderCreated(SalesOrder $order, array $preparedItems): void
    {
        foreach ($preparedItems as $item) {
            $product = Product::query()
                ->lockForUpdate()
                ->find((string) ($item['product_id'] ?? ''));

            if (! $product) {
                throw new RuntimeException("Produk {$item['product_id']} tidak ditemukan saat checkout.");
            }

            $variantRows = $this->extractVariantRows($product);
            $targetSku = trim((string) ($item['variant_sku'] ?? ''));
            $targetIndex = null;

            foreach ($variantRows as $index => $variantRow) {
                if (strcasecmp($this->resolveSku($product, $variantRow), $targetSku) === 0) {
                    $targetIndex = $index;
                    break;
                }
            }

            if ($targetIndex === null) {
                throw new RuntimeException("SKU {$targetSku} tidak ditemukan saat checkout.");
            }

            $targetVariant = $variantRows[$targetIndex];
            $warehouse = trim((string) ($item['warehouse'] ?? '')) ?: $this->resolveWarehouse($targetVariant, $product);
            $warehouseStock = $this->normalizeWarehouseStock(
                $targetVariant['warehouse_stock'] ?? null,
                $warehouse,
                (int) ($targetVariant['stock'] ?? 0)
            );

            $currentStock = (int) ($warehouseStock[$warehouse] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            if ($currentStock < $quantity) {
                throw new RuntimeException("Stok tidak cukup untuk SKU {$targetSku} saat checkout.");
            }

            $warehouseStock[$warehouse] = $currentStock - $quantity;
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

            StockMutation::query()->create([
                'product_id' => (string) $product->id,
                'variant_sku' => $targetSku,
                'type' => 'out',
                'quantity' => -$quantity,
                'reference' => 'checkout:' . (string) $order->order_number,
                'note' => sprintf(
                    'Checkout customer | warehouse %s | sebelum %d | sesudah %d',
                    $warehouse,
                    $currentStock,
                    $warehouseStock[$warehouse]
                ),
                'user_id' => null,
            ]);
        }

        $this->markStockAsDeducted($order, 'checkout_created');
    }

    private function hasDeductedStock(SalesOrder $order): bool
    {
        $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];

        return is_string($metadata['stock_deducted_at'] ?? null) && trim((string) $metadata['stock_deducted_at']) !== '';
    }

    private function markStockAsDeducted(SalesOrder $order, string $source): void
    {
        $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];
        $metadata['stock_deducted_at'] = now()->toISOString();
        $metadata['stock_deduction_source'] = $source;
        $order->shipping_metadata = $metadata;
        $order->save();
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

    private function resolveWeightInGram(array $variant, Product $product): ?int
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

        return null;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function describeCheckoutItem(Product $product, array $variant): string
    {
        $variantLabel = trim((string) ($variant['label'] ?? $variant['variant_name'] ?? ''));
        if ($variantLabel !== '' && ! in_array(Str::lower($variantLabel), ['default', 'default variant'], true)) {
            return sprintf('%s (%s)', (string) $product->name, $variantLabel);
        }

        return (string) $product->name;
    }

    private function resolvePackagingWeightInGram(): int
    {
        return max(0, (int) config('services.rajaongkir.packaging_weight_grams', 0));
    }

    private function isStrictShippingMode(): bool
    {
        return (bool) config('services.rajaongkir.strict_mode', false);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function syncInvoiceFromOrder(SalesOrder $order, array $payload = []): Invoice
    {
        $invoice = $order->invoice()->firstOrNew();
        if (! $invoice->exists) {
            $invoice->invoice_number = $this->generateInvoiceNumber();
        }

        $paymentType = Str::lower(trim((string) ($payload['payment_type'] ?? $order->payment_method ?? '')));
        $invoiceStatus = $this->mapInvoicePaymentStatus((string) ($order->payment_status ?? 'pending'));
        $settledAt = $order->settled_at ?? $this->resolveSettlementDate($payload);

        $invoice->payment_method = $this->resolveInvoicePaymentMethod($paymentType, $payload, $invoice->payment_method);
        $invoice->payment_va_number = $this->resolveInvoiceVaNumber($payload) ?? $invoice->payment_va_number;
        $invoice->payment_bill_key = $this->resolveInvoiceBillKey($payload) ?? $invoice->payment_bill_key;
        $invoice->amount_total = (float) $order->total_amount;
        $invoice->payment_status = $invoiceStatus;
        $invoice->snap_token = trim((string) ($order->snap_token ?? '')) !== ''
            ? (string) $order->snap_token
            : $invoice->snap_token;
        $invoice->expiry_time = $this->resolveInvoiceExpiryTime($payload) ?? $invoice->expiry_time;

        if ($invoiceStatus === 'paid') {
            $invoice->paid_at = $settledAt ?? $invoice->paid_at ?? now();
        }

        $invoice->save();

        return $invoice->fresh();
    }

    private function mapInvoicePaymentStatus(string $paymentStatus): string
    {
        $normalized = Str::lower(trim($paymentStatus));

        return match (true) {
            in_array($normalized, ['settlement', 'capture', 'paid'], true) => 'paid',
            in_array($normalized, ['expire', 'expired'], true) => 'expired',
            in_array($normalized, ['deny', 'cancel', 'cancelled', 'failure', 'failed'], true) => 'failed',
            default => 'pending',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveInvoicePaymentMethod(string $paymentType, array $payload, ?string $fallback = null): string
    {
        $bank = Str::lower(trim((string) Arr::get($payload, 'va_numbers.0.bank', '')));

        if ($paymentType === 'bank_transfer' && $bank !== '') {
            return match ($bank) {
                'bca' => 'BCA Virtual Account',
                'bni' => 'BNI Virtual Account',
                'bri' => 'BRI Virtual Account',
                default => strtoupper($bank) . ' Virtual Account',
            };
        }

        if ($paymentType === 'echannel') {
            return 'Mandiri Bill';
        }

        if ($this->resolveInvoiceVaNumber($payload) !== null && trim((string) Arr::get($payload, 'permata_va_number', '')) !== '') {
            return 'Permata Virtual Account';
        }

        return match ($paymentType) {
            'gopay' => 'GoPay',
            'shopeepay' => 'ShopeePay',
            'qris' => 'QRIS',
            'credit_card' => 'Kartu Kredit',
            'debit_card' => 'Kartu Debit',
            'cstore' => ucfirst((string) Arr::get($payload, 'store', 'Gerai')),
            'midtrans_snap' => 'Midtrans Snap',
            '' => trim((string) ($fallback ?? 'Midtrans Snap')) !== '' ? (string) $fallback : 'Midtrans Snap',
            default => (string) Str::of($paymentType)->replace(['_', '-'], ' ')->title(),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveInvoiceVaNumber(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'va_numbers.0.va_number'),
            Arr::get($payload, 'permata_va_number'),
            Arr::get($payload, 'payment_code'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveInvoiceBillKey(array $payload): ?string
    {
        $candidate = Arr::get($payload, 'bill_key');
        if (! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $normalized = trim((string) $candidate);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveInvoiceExpiryTime(array $payload): ?Carbon
    {
        $candidate = Arr::get($payload, 'expiry_time');
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        try {
            return Carbon::parse($candidate);
        } catch (\Throwable) {
            return null;
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = sprintf('SO-%s-%04d', now()->format('Ymd'), random_int(0, 9999));
        } while (SalesOrder::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function generateInvoiceNumber(): string
    {
        $dateSegment = now()->format('Ymd');
        $prefix = sprintf('INV/%s/ENT/', $dateSegment);

        do {
            $sequence = Invoice::query()
                ->where('invoice_number', 'like', "{$prefix}%")
                ->count() + 1;
            $candidate = sprintf('%s%03d', $prefix, $sequence);
        } while (Invoice::query()->where('invoice_number', $candidate)->exists());

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
