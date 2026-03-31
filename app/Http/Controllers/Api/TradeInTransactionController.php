<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTradeInTransactionRequest;
use App\Models\TradeInTransaction;
use App\Services\TradeInTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TradeInTransactionController extends Controller
{
    public function __construct(private readonly TradeInTransactionService $service)
    {
    }

    public function store(StoreTradeInTransactionRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();
            $transaction = $this->service->createForCustomer($user, $request->validated(), $request->file('photos', []));

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan trade-in berhasil dikirim.',
                'data' => $this->transformTransaction($transaction),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi pengajuan trade-in gagal.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }

    private function transformTransaction(TradeInTransaction $transaction): array
    {
        return [
            'id' => (string) $transaction->id,
            'transaction_number' => (string) $transaction->transaction_number,
            'status' => (string) $transaction->status,
            'estimated_amount' => (float) $transaction->estimated_amount,
            'customer_name' => (string) $transaction->customer_name,
            'requested_product_name' => $transaction->requested_product_name,
            'photo_count' => $transaction->photos->count(),
            'created_at' => optional($transaction->created_at)?->toISOString(),
        ];
    }
}
