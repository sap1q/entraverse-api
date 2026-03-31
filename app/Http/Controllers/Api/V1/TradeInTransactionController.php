<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTradeInTransactionStatusRequest;
use App\Models\Admin;
use App\Models\TradeInTransaction;
use App\Models\TradeInTransactionPhoto;
use App\Services\TradeInTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TradeInTransactionController extends Controller
{
    public function __construct(private readonly TradeInTransactionService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $transactions = $this->service->paginate($request->query());

        return response()->json([
            'success' => true,
            'message' => 'Daftar transaksi trade-in berhasil diambil.',
            'data' => collect($transactions->items())->map(
                fn (TradeInTransaction $transaction): array => $this->transformTransaction($transaction)
            )->values()->all(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function updateStatus(UpdateTradeInTransactionStatusRequest $request, string $transactionId): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $transaction = $this->service->updateStatus(
            $admin,
            $transactionId,
            (string) $request->validated('status'),
            $request->validated('admin_notes')
        );

        return response()->json([
            'success' => true,
            'message' => 'Status transaksi trade-in berhasil diperbarui.',
            'data' => $this->transformTransaction($transaction),
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    private function transformTransaction(TradeInTransaction $transaction): array
    {
        $coverPhoto = $transaction->photos
            ->sortByDesc(fn (TradeInTransactionPhoto $photo) => $photo->is_primary)
            ->first();

        $photos = $transaction->photos->map(function (TradeInTransactionPhoto $photo): array {
            return [
                'id' => (string) $photo->id,
                'slot_id' => $photo->slot_id,
                'label' => $photo->label,
                'image_url' => $this->resolvePhotoUrl($photo),
                'mime_type' => $photo->mime_type,
                'file_size' => $photo->file_size,
                'sort_order' => $photo->sort_order,
                'is_primary' => $photo->is_primary,
                'created_at' => optional($photo->created_at)?->toISOString(),
            ];
        })->values();

        return [
            'id' => (string) $transaction->id,
            'transaction_number' => (string) $transaction->transaction_number,
            'customer_name' => (string) $transaction->customer_name,
            'customer_phone' => $transaction->customer_phone,
            'customer_email' => $transaction->customer_email,
            'customer_city' => $transaction->customer_city,
            'customer_address' => $transaction->customer_address,
            'trade_in_only' => (bool) $transaction->trade_in_only,
            'requested_product' => $transaction->requestedProduct ? [
                'id' => (string) $transaction->requestedProduct->id,
                'name' => (string) $transaction->requestedProduct->name,
                'spu' => (string) ($transaction->requestedProduct->spu ?? ''),
            ] : null,
            'requested_product_name' => $transaction->requested_product_name,
            'requested_product_variant_sku' => $transaction->requested_product_variant_sku,
            'device_brand' => $transaction->device_brand,
            'device_model' => $transaction->device_model,
            'device_variant' => $transaction->device_variant,
            'physical_condition' => $transaction->physical_condition,
            'device_age' => $transaction->device_age,
            'service_history' => $transaction->service_history,
            'accessory_summary' => is_array($transaction->accessory_summary) ? $transaction->accessory_summary : [],
            'answers' => is_array($transaction->answers) ? $transaction->answers : [],
            'estimated_amount' => (float) $transaction->estimated_amount,
            'offered_amount' => (float) $transaction->offered_amount,
            'status' => (string) $transaction->status,
            'fulfillment_method' => (string) $transaction->fulfillment_method,
            'shipment_courier' => $transaction->shipment_courier,
            'shipment_tracking_number' => $transaction->shipment_tracking_number,
            'customer_notes' => $transaction->customer_notes,
            'admin_notes' => $transaction->admin_notes,
            'reviewed_at' => optional($transaction->reviewed_at)?->toISOString(),
            'completed_at' => optional($transaction->completed_at)?->toISOString(),
            'reviewer' => $transaction->reviewer ? [
                'id' => (string) $transaction->reviewer->id,
                'name' => (string) $transaction->reviewer->name,
                'email' => (string) $transaction->reviewer->email,
            ] : null,
            'photo_count' => $photos->count(),
            'cover_photo_url' => $coverPhoto ? $this->resolvePhotoUrl($coverPhoto) : null,
            'photos' => $photos->all(),
            'created_at' => optional($transaction->created_at)?->toISOString(),
            'updated_at' => optional($transaction->updated_at)?->toISOString(),
        ];
    }

    private function resolvePhotoUrl(TradeInTransactionPhoto $photo): ?string
    {
        $directUrl = trim((string) ($photo->image_url ?? ''));
        if ($directUrl !== '') {
            return $directUrl;
        }

        $path = trim((string) ($photo->image_path ?? ''));
        if ($path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
