<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly MidtransService $midtransService
    ) {
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->all();
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $statusCode = trim((string) ($payload['status_code'] ?? ''));
        $grossAmount = trim((string) ($payload['gross_amount'] ?? ''));
        $signatureKey = trim((string) ($payload['signature_key'] ?? ''));

        if (! $this->midtransService->verifyCallbackSignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Signature callback Midtrans tidak valid.',
            ], 403);
        }

        try {
            $order = $this->checkoutService->handlePaymentCallback($payload);

            return response()->json([
                'success' => true,
                'message' => 'Callback Midtrans diproses.',
                'data' => [
                    'order_id' => (string) $order->id,
                    'order_number' => (string) $order->order_number,
                    'payment_status' => (string) ($order->payment_status ?? 'pending'),
                    'status' => (string) $order->status,
                ],
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('Midtrans callback runtime warning.', [
                'message' => $exception->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            Log::error('Midtrans callback failed.', [
                'error' => $throwable->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses callback pembayaran.',
            ], 500);
        }
    }
}

