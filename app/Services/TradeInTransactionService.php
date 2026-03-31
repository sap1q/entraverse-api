<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Admin;
use App\Models\TradeInTransaction;
use App\Models\TradeInTransactionPhoto;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TradeInTransactionService
{
    private const PHOTO_LABELS = [
        'front' => 'Depan perangkat',
        'back' => 'Belakang perangkat',
        'screen_on' => 'Perangkat menyala',
        'damage_detail' => 'Detail kerusakan',
        'accessories' => 'Kelengkapan',
    ];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));
        $driver = DB::connection()->getDriverName();

        return TradeInTransaction::query()
            ->with(['photos', 'reviewer:id,name,email', 'requestedProduct:id,name,spu'])
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(function (Builder $nested) use ($search): void {
                        $nested
                            ->where('transaction_number', 'ilike', "%{$search}%")
                            ->orWhere('customer_name', 'ilike', "%{$search}%")
                            ->orWhere('customer_email', 'ilike', "%{$search}%")
                            ->orWhere('device_brand', 'ilike', "%{$search}%")
                            ->orWhere('device_model', 'ilike', "%{$search}%");
                    });

                    return;
                }

                $keyword = '%' . strtolower($search) . '%';

                $query->where(function (Builder $nested) use ($keyword): void {
                    $nested
                        ->whereRaw('LOWER(transaction_number) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(customer_name) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(customer_email) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(device_brand) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(device_model) LIKE ?', [$keyword]);
                });
            })
            ->latest()
            ->paginate($perPage)
            ->appends($filters);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $photos
     */
    public function createForCustomer(User $user, array $payload, array $photos): TradeInTransaction
    {
        return DB::transaction(function () use ($payload, $photos, $user): TradeInTransaction {
            $requestedProductId = trim((string) ($payload['requested_product_id'] ?? ''));
            $requestedProduct = $requestedProductId !== '' ? Product::query()->find($requestedProductId) : null;
            $photoSlots = is_array($payload['photo_slots'] ?? null) ? array_values($payload['photo_slots']) : [];

            $transaction = TradeInTransaction::query()->create([
                'transaction_number' => $this->generateTransactionNumber(),
                'user_id' => (string) $user->id,
                'requested_product_id' => $requestedProduct?->id,
                'requested_product_name' => $requestedProduct?->name ?? ($payload['requested_product_name'] ?? null),
                'requested_product_variant_sku' => $payload['requested_product_variant_sku'] ?? null,
                'customer_name' => (string) $payload['customer_name'],
                'customer_phone' => (string) $payload['customer_phone'],
                'customer_email' => (string) $payload['customer_email'],
                'customer_city' => $payload['customer_city'] ?? null,
                'customer_address' => (string) $payload['customer_address'],
                'trade_in_only' => (bool) ($payload['trade_in_only'] ?? false),
                'device_brand' => $payload['device_brand'] ?? null,
                'device_model' => (string) $payload['device_model'],
                'device_variant' => $payload['device_variant'] ?? null,
                'physical_condition' => (string) $payload['physical_condition'],
                'device_age' => (string) $payload['device_age'],
                'service_history' => (string) $payload['service_history'],
                'accessory_summary' => is_array($payload['accessory_summary'] ?? null) ? array_values($payload['accessory_summary']) : [],
                'answers' => [
                    'physical_condition' => $payload['physical_condition'],
                    'device_age' => $payload['device_age'],
                    'service_history' => $payload['service_history'],
                    'accessory_summary' => is_array($payload['accessory_summary'] ?? null) ? array_values($payload['accessory_summary']) : [],
                    'photo_slots' => $photoSlots,
                ],
                'estimated_amount' => max(0, (float) ($payload['estimated_amount'] ?? 0)),
                'offered_amount' => 0,
                'status' => 'menunggu_review',
                'fulfillment_method' => 'belum_dipilih',
                'customer_notes' => $payload['customer_notes'] ?? null,
            ]);

            foreach ($photos as $index => $photo) {
                if (! $photo instanceof UploadedFile) {
                    continue;
                }

                $slotId = isset($photoSlots[$index]) ? (string) $photoSlots[$index] : null;
                $storedPath = $this->storePhoto($transaction, $photo, $index);

                TradeInTransactionPhoto::query()->create([
                    'trade_in_transaction_id' => (string) $transaction->id,
                    'slot_id' => $slotId,
                    'label' => $slotId !== null ? (self::PHOTO_LABELS[$slotId] ?? $slotId) : null,
                    'image_path' => $storedPath,
                    'image_url' => Storage::disk('public')->url($storedPath),
                    'mime_type' => $photo->getClientMimeType(),
                    'file_size' => $photo->getSize(),
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }

            return $transaction->fresh(['photos']);
        });
    }

    public function updateStatus(Admin $admin, string $transactionId, string $status, ?string $adminNotes = null): TradeInTransaction
    {
        /** @var TradeInTransaction $transaction */
        $transaction = TradeInTransaction::query()
            ->with(['photos', 'reviewer:id,name,email', 'requestedProduct:id,name,spu'])
            ->findOrFail($transactionId);

        $transaction->status = $status;

        if ($adminNotes !== null) {
            $transaction->admin_notes = trim($adminNotes) !== '' ? trim($adminNotes) : null;
        }

        if (in_array($status, ['disetujui', 'ditolak'], true)) {
            $transaction->reviewed_by = (string) $admin->id;
            $transaction->reviewed_at = now();
        }

        if ($status === 'selesai') {
            $transaction->completed_at = now();
        }

        $transaction->save();

        return $transaction->fresh(['photos', 'reviewer:id,name,email', 'requestedProduct:id,name,spu']);
    }

    private function generateTransactionNumber(): string
    {
        do {
            $number = sprintf('TI-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6)));
        } while (TradeInTransaction::query()->where('transaction_number', $number)->exists());

        return $number;
    }

    private function storePhoto(TradeInTransaction $transaction, UploadedFile $photo, int $index): string
    {
        $filename = sprintf(
            '%02d_%s.%s',
            $index + 1,
            Str::random(12),
            $photo->getClientOriginalExtension()
        );

        return $photo->storeAs('trade-in/' . $transaction->id, $filename, 'public');
    }
}
