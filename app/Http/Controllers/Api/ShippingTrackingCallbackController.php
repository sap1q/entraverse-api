<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShippingTrackingCallbackController extends Controller
{
    public function __construct(private readonly SalesOrderService $salesOrderService)
    {
    }

    public function callback(Request $request): JsonResponse
    {
        $configuredSecret = trim((string) config('services.tracking.webhook_secret', ''));
        $providedSecret = trim((string) ($request->header('X-Tracking-Webhook-Secret') ?? $request->input('secret', '')));

        if ($configuredSecret !== '' && ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking webhook secret tidak valid.',
            ], 403);
        }

        try {
            $order = $this->salesOrderService->syncTrackingStatus($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Status tracking vendor berhasil disinkronkan.',
                'data' => [
                    'order_id' => (string) $order->id,
                    'order_number' => (string) $order->order_number,
                    'status' => (string) $order->status,
                    'tracking_number' => data_get($order->shipping_metadata, 'tracking_number'),
                    'tracking_status' => data_get($order->shipping_metadata, 'tracking_status'),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?? 'Payload tracking vendor tidak valid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $throwable) {
            Log::error('Vendor tracking webhook failed.', [
                'error' => $throwable->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses tracking vendor.',
            ], 500);
        }
    }
}
