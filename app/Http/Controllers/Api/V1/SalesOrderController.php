<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesOrderRequest;
use App\Http\Requests\UpdateSalesOrderFulfillmentRequest;
use App\Http\Requests\UpdateSalesOrderStatusRequest;
use App\Models\Admin;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->service->paginate($request->query());

        return response()->json([
            'success' => true,
            'message' => 'Daftar pesanan berhasil diambil.',
            'data' => collect($orders->items())->map(
                fn (SalesOrder $order): array => $this->transformOrder($order)
            )->values()->all(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $catalog = $this->service->catalog($request->query());

        return response()->json([
            'success' => true,
            'message' => 'Katalog pemesanan berhasil diambil.',
            'data' => $catalog,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        try {
            /** @var Admin $admin */
            $admin = $request->user();
            $order = $this->service->create($admin, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat.',
                'data' => $this->transformOrder($order),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi pesanan gagal.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }

    public function show(string $orderId): JsonResponse
    {
        $order = $this->service->find($orderId);

        return response()->json([
            'success' => true,
            'message' => 'Detail pesanan berhasil diambil.',
            'data' => $this->transformOrder($order),
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function fulfillment(UpdateSalesOrderFulfillmentRequest $request, string $orderId): JsonResponse
    {
        try {
            /** @var Admin $admin */
            $admin = $request->user();
            $order = $this->service->updateFulfillment($admin, $orderId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Status pemenuhan pesanan berhasil diperbarui.',
                'data' => $this->transformOrder($order),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Pembaruan pemenuhan pesanan gagal.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }

    public function updateStatus(UpdateSalesOrderStatusRequest $request, string $orderId): JsonResponse
    {
        try {
            /** @var Admin $admin */
            $admin = $request->user();
            $order = $this->service->updateStatus($admin, $orderId, (string) $request->validated('status'));

            return response()->json([
                'success' => true,
                'message' => 'Status pesanan berhasil diperbarui.',
                'data' => $this->transformOrder($order),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Pembaruan status pesanan gagal.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }

    public function destroy(string $orderId): JsonResponse
    {
        try {
            $this->service->delete($orderId);

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dihapus.',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak dapat dihapus.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }

    private function transformOrder(SalesOrder $order): array
    {
        $tracking = $this->resolveTrackingData($order);

        return [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'customer_name' => (string) $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'status' => (string) $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount' => (float) $order->total_amount,
            'shipping_courier' => $order->shipping_courier,
            'shipping_service' => $order->shipping_service,
            'shipping_etd' => $order->shipping_etd,
            'shipping_weight' => $order->shipping_weight,
            'shipping_metadata' => is_array($order->shipping_metadata) ? $order->shipping_metadata : [],
            'tracking_number' => $tracking['number'],
            'tracking_url' => $tracking['url'],
            'notes' => $order->notes,
            'created_by' => $order->creator ? [
                'id' => (string) $order->creator->id,
                'name' => (string) $order->creator->name,
                'email' => (string) $order->creator->email,
            ] : null,
            'invoice' => $order->invoice ? [
                'id' => (string) $order->invoice->id,
                'invoice_number' => (string) $order->invoice->invoice_number,
                'payment_method' => $order->invoice->payment_method,
                'payment_va_number' => $order->invoice->payment_va_number,
                'payment_bill_key' => $order->invoice->payment_bill_key,
                'amount_total' => (float) $order->invoice->amount_total,
                'payment_status' => (string) $order->invoice->payment_status,
                'snap_token' => $order->invoice->snap_token,
                'expiry_time' => optional($order->invoice->expiry_time)?->toISOString(),
                'paid_at' => optional($order->invoice->paid_at)?->toISOString(),
            ] : null,
            'items' => $order->items->map(function ($item): array {
                return [
                    'id' => (string) $item->id,
                    'product_id' => (string) $item->product_id,
                    'product_name' => (string) $item->product_name,
                    'variant_name' => $item->variant_name,
                    'variant_sku' => (string) $item->variant_sku,
                    'warehouse' => (string) $item->warehouse,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'landed_cost' => (float) $item->landed_cost,
                    'line_total' => (float) $item->line_total,
                    'product_image' => $this->resolveOrderItemImage($item),
                    'metadata' => is_array($item->metadata) ? $item->metadata : [],
                ];
            })->values()->all(),
            'created_at' => optional($order->created_at)?->toISOString(),
            'updated_at' => optional($order->updated_at)?->toISOString(),
            'settled_at' => optional($order->settled_at)?->toISOString(),
        ];
    }

    /**
     * @return array{number: string|null, url: string|null}
     */
    private function resolveTrackingData(SalesOrder $order): array
    {
        $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];
        $nestedTracking = Arr::get($metadata, 'tracking', []);

        $trackingNumber = collect([
            Arr::get($metadata, 'tracking_number'),
            Arr::get($nestedTracking, 'number'),
            Arr::get($nestedTracking, 'tracking_number'),
        ])->map(
            fn ($value): string => is_string($value) ? trim($value) : ''
        )->first(
            fn (string $value): bool => $value !== '',
            null
        );

        $trackingUrl = collect([
            Arr::get($metadata, 'tracking_url'),
            Arr::get($nestedTracking, 'url'),
            Arr::get($nestedTracking, 'tracking_url'),
        ])->map(
            fn ($value): string => is_string($value) ? trim($value) : ''
        )->first(
            fn (string $value): bool => $value !== '' && Str::startsWith($value, ['http://', 'https://']),
            null
        );

        return [
            'number' => $trackingNumber,
            'url' => $trackingUrl,
        ];
    }

    private function resolveOrderItemImage(SalesOrderItem $item): ?string
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $rawImage = collect([
            Arr::get($metadata, 'product_image'),
            Arr::get($metadata, 'image'),
            Arr::get($metadata, 'thumbnail'),
            Arr::get($metadata, 'main_image'),
        ])->map(
            fn ($value): string => is_string($value) ? trim($value) : ''
        )->first(
            fn (string $value): bool => $value !== '',
            ''
        );

        if ($rawImage !== '') {
            return $this->normalizeAssetUrl($rawImage);
        }

        if (! $item->relationLoaded('product')) {
            return null;
        }

        $product = $item->product;
        if (! $product instanceof Product) {
            return null;
        }

        $photos = is_array($product->photos) ? $product->photos : [];
        foreach ($photos as $photo) {
            if (is_string($photo) && trim($photo) !== '') {
                return $this->normalizeAssetUrl($photo);
            }

            if (is_array($photo)) {
                $photoUrl = trim((string) ($photo['url'] ?? ''));
                if ($photoUrl !== '') {
                    return $this->normalizeAssetUrl($photoUrl);
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
}
