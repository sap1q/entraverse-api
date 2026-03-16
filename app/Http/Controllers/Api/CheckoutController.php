<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessCheckoutRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly MidtransService $midtransService
    ) {
    }

    public function process(ProcessCheckoutRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $result = $this->checkoutService->processCheckout($user, $request->validated());
            $order = $result['order'];

            return response()->json([
                'success' => true,
                'message' => 'Checkout berhasil diproses.',
                'data' => [
                    'order' => $this->transformOrder($order),
                    'snap_token' => $result['snap_token'],
                    'snap_redirect_url' => $result['snap_redirect_url'],
                    'midtrans_client_key' => $this->midtransService->clientKey(),
                    'midtrans_snap_js_url' => $this->midtransService->snapJsUrl(),
                    'shipping' => $result['shipping'],
                    'shipping_weight' => $result['shipping_weight'],
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi checkout gagal.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function transformOrder(SalesOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'payment_status' => (string) ($order->payment_status ?? 'pending'),
            'customer_name' => (string) $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'shipping_courier' => $order->shipping_courier,
            'shipping_service' => $order->shipping_service,
            'shipping_etd' => $order->shipping_etd,
            'shipping_weight' => (int) ($order->shipping_weight ?? 0),
            'subtotal' => (float) $order->subtotal,
            'shipping_cost' => (float) $order->shipping_cost,
            'total_amount' => (float) $order->total_amount,
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
                    'line_total' => (float) $item->line_total,
                    'metadata' => is_array($item->metadata) ? $item->metadata : [],
                ];
            })->values()->all(),
            'created_at' => optional($order->created_at)?->toISOString(),
            'updated_at' => optional($order->updated_at)?->toISOString(),
        ];
    }
}

