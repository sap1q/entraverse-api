<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesOrderRequest;
use App\Models\Admin;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private function transformOrder(SalesOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'customer_name' => (string) $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount' => (float) $order->total_amount,
            'notes' => $order->notes,
            'created_by' => $order->creator ? [
                'id' => (string) $order->creator->id,
                'name' => (string) $order->creator->name,
                'email' => (string) $order->creator->email,
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
                    'metadata' => is_array($item->metadata) ? $item->metadata : [],
                ];
            })->values()->all(),
            'created_at' => optional($order->created_at)?->toISOString(),
            'updated_at' => optional($order->updated_at)?->toISOString(),
        ];
    }
}

