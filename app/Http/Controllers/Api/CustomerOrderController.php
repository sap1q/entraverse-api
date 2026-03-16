<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Services\MidtransService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CustomerOrderController extends Controller
{
    private const ORDER_FILTER_ALL = 'all';
    private const ORDER_FILTER_ONGOING = 'ongoing';
    private const ORDER_FILTER_SUCCESS = 'success';
    private const ORDER_FILTER_CANCELLED = 'cancelled';

    private const PAYMENT_PENDING_STATUSES = ['pending', 'unfinish', 'challenge'];
    private const PAYMENT_SUCCESS_STATUSES = ['settlement', 'capture', 'paid'];
    private const PAYMENT_FAILED_STATUSES = ['deny', 'cancel', 'cancelled', 'expire', 'expired', 'failure', 'failed'];

    private const PROGRESS_STAGES = [
        1 => ['code' => 'waiting_payment', 'label' => 'Menunggu Pembayaran'],
        2 => ['code' => 'waiting_confirmation', 'label' => 'Menunggu Konfirmasi'],
        3 => ['code' => 'order_shipped', 'label' => 'Pesanan Dikirim'],
        4 => ['code' => 'order_delivered', 'label' => 'Pesanan Terkirim'],
        5 => ['code' => 'completed', 'label' => 'Selesai'],
    ];

    public function __construct(private readonly MidtransService $midtransService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = trim((string) $request->query('status', ''));
        $filter = $this->normalizeFilter((string) $request->query('filter', self::ORDER_FILTER_ALL));
        $perPage = max(1, min((int) $request->query('per_page', 10), 50));

        $ordersQuery = SalesOrder::query()
            ->with(['items.product'])
            ->where('user_id', $user->id);

        if ($status !== '' && $status !== 'all') {
            $ordersQuery->where('payment_status', $status);
        }

        $this->applyFilter($ordersQuery, $filter);

        $orders = $ordersQuery
            ->latest()
            ->paginate($perPage)
            ->appends([
                'status' => $status,
                'filter' => $filter,
                'per_page' => $perPage,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Daftar transaksi berhasil diambil.',
            'data' => collect($orders->items())
                ->map(fn (SalesOrder $order): array => $this->transformOrder($order))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
            'filters' => [
                'active' => $filter,
                'items' => $this->availableFilters(),
            ],
            'status_catalog' => [
                'steps' => $this->progressStages(),
            ],
        ]);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = SalesOrder::query()
            ->with(['items.product'])
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail transaksi berhasil diambil.',
            'data' => $this->transformOrder($order),
            'status_catalog' => [
                'steps' => $this->progressStages(),
            ],
        ]);
    }

    public function payment(Request $request, string $orderId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = SalesOrder::query()
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        $paymentStatus = strtolower(trim((string) ($order->payment_status ?? 'pending')));
        if (! in_array($paymentStatus, ['pending', 'unfinish'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini tidak memerlukan pembayaran ulang.',
            ], 422);
        }

        $paymentPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $snap = is_array($paymentPayload['snap'] ?? null) ? $paymentPayload['snap'] : [];
        $snapToken = trim((string) ($order->snap_token ?? ''));
        $snapRedirectUrl = trim((string) ($snap['redirect_url'] ?? ''));

        if ($snapToken === '' && $snapRedirectUrl === '') {
            return response()->json([
                'success' => false,
                'message' => 'Token pembayaran Midtrans tidak tersedia untuk pesanan ini.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data pembayaran transaksi berhasil diambil.',
            'data' => [
                'order' => [
                    'id' => (string) $order->id,
                    'order_number' => (string) $order->order_number,
                    'payment_status' => (string) ($order->payment_status ?? 'pending'),
                    'status' => (string) $order->status,
                    'status_step' => $this->resolveStatusMetadata($order)['step'],
                    'total_amount' => (float) $order->total_amount,
                ],
                'snap_token' => $snapToken !== '' ? $snapToken : null,
                'snap_redirect_url' => $snapRedirectUrl !== '' ? $snapRedirectUrl : null,
                'midtrans_client_key' => $this->midtransService->clientKey(),
                'midtrans_snap_js_url' => $this->midtransService->snapJsUrl(),
            ],
        ]);
    }

    private function transformOrder(SalesOrder $order): array
    {
        $statusMetadata = $this->resolveStatusMetadata($order);
        $tracking = $this->resolveTracking($order);
        $firstItem = $order->items->first();

        return [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'status_stage_code' => $statusMetadata['stage_code'],
            'status_step' => $statusMetadata['step'],
            'status_label' => $statusMetadata['label'],
            'status_group' => $statusMetadata['group'],
            'payment_status' => (string) ($order->payment_status ?? 'pending'),
            'payment_method' => $order->payment_method,
            'payment_method_label' => $this->resolvePaymentMethodLabel($order),
            'subtotal' => (float) $order->subtotal,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount' => (float) $order->total_amount,
            'shipping_courier' => $order->shipping_courier,
            'shipping_service' => $order->shipping_service,
            'shipping_etd' => $order->shipping_etd,
            'can_track_package' => $statusMetadata['can_track_package'],
            'tracking_number' => $tracking['number'],
            'tracking_url' => $tracking['url'],
            'customer_name' => (string) $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'items' => $order->items->map(function (SalesOrderItem $item): array {
                return [
                    'id' => (string) $item->id,
                    'product_id' => (string) $item->product_id,
                    'product_name' => (string) $item->product_name,
                    'variant_name' => $item->variant_name,
                    'variant_sku' => (string) $item->variant_sku,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                    'product_image' => $this->resolveOrderItemImage($item),
                ];
            })->values()->all(),
            'primary_item' => $firstItem instanceof SalesOrderItem ? [
                'id' => (string) $firstItem->id,
                'product_id' => (string) $firstItem->product_id,
                'product_name' => (string) $firstItem->product_name,
                'variant_name' => $firstItem->variant_name,
                'variant_sku' => (string) $firstItem->variant_sku,
                'quantity' => (int) $firstItem->quantity,
                'unit_price' => (float) $firstItem->unit_price,
                'line_total' => (float) $firstItem->line_total,
                'product_image' => $this->resolveOrderItemImage($firstItem),
            ] : null,
            'created_at' => optional($order->created_at)?->toISOString(),
            'updated_at' => optional($order->updated_at)?->toISOString(),
        ];
    }

    private function normalizeFilter(string $rawFilter): string
    {
        $normalized = Str::lower(trim($rawFilter));

        return match ($normalized) {
            'ongoing', 'berlangsung' => self::ORDER_FILTER_ONGOING,
            'success', 'berhasil' => self::ORDER_FILTER_SUCCESS,
            'cancelled', 'canceled', 'dibatalkan' => self::ORDER_FILTER_CANCELLED,
            default => self::ORDER_FILTER_ALL,
        };
    }

    private function applyFilter(Builder $query, string $filter): void
    {
        if ($filter === self::ORDER_FILTER_ALL) {
            return;
        }

        if ($filter === self::ORDER_FILTER_SUCCESS) {
            $query->where('status', 'selesai');
            return;
        }

        if ($filter === self::ORDER_FILTER_CANCELLED) {
            $query->where(function (Builder $scoped): void {
                $scoped
                    ->where('status', 'dibatalkan')
                    ->orWhereIn('payment_status', self::PAYMENT_FAILED_STATUSES);
            });
            return;
        }

        $query
            ->whereIn('status', ['dibayar', 'diproses', 'dikirim', 'terkirim'])
            ->where(function (Builder $scoped): void {
                $scoped
                    ->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', self::PAYMENT_FAILED_STATUSES);
            });
    }

    /**
     * @return array{
     *   stage_code: string,
     *   step: int,
     *   label: string,
     *   group: string,
     *   can_track_package: bool
     * }
     */
    private function resolveStatusMetadata(SalesOrder $order): array
    {
        $status = Str::lower(trim((string) $order->status));
        $paymentStatus = Str::lower(trim((string) ($order->payment_status ?? 'pending')));

        $isCancelled = $status === 'dibatalkan' || in_array($paymentStatus, self::PAYMENT_FAILED_STATUSES, true);
        if ($isCancelled) {
            return [
                'stage_code' => 'cancelled',
                'step' => 1,
                'label' => 'Dibatalkan',
                'group' => self::ORDER_FILTER_CANCELLED,
                'can_track_package' => false,
            ];
        }

        if ($status === 'selesai') {
            $stage = self::PROGRESS_STAGES[5];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 5,
                'label' => (string) $stage['label'],
                'group' => self::ORDER_FILTER_SUCCESS,
                'can_track_package' => true,
            ];
        }

        if ($status === 'terkirim') {
            $stage = self::PROGRESS_STAGES[4];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 4,
                'label' => (string) $stage['label'],
                'group' => self::ORDER_FILTER_ONGOING,
                'can_track_package' => true,
            ];
        }

        if ($status === 'dikirim') {
            $stage = self::PROGRESS_STAGES[3];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 3,
                'label' => (string) $stage['label'],
                'group' => self::ORDER_FILTER_ONGOING,
                'can_track_package' => true,
            ];
        }

        if ($status === 'diproses' || in_array($paymentStatus, self::PAYMENT_SUCCESS_STATUSES, true)) {
            $stage = self::PROGRESS_STAGES[2];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 2,
                'label' => (string) $stage['label'],
                'group' => self::ORDER_FILTER_ONGOING,
                'can_track_package' => false,
            ];
        }

        if (! in_array($paymentStatus, self::PAYMENT_PENDING_STATUSES, true) && $paymentStatus !== '') {
            $stage = self::PROGRESS_STAGES[2];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 2,
                'label' => (string) $stage['label'],
                'group' => self::ORDER_FILTER_ONGOING,
                'can_track_package' => false,
            ];
        }

        $stage = self::PROGRESS_STAGES[1];

        return [
            'stage_code' => (string) $stage['code'],
            'step' => 1,
            'label' => (string) $stage['label'],
            'group' => self::ORDER_FILTER_ONGOING,
            'can_track_package' => false,
        ];
    }

    private function resolvePaymentMethodLabel(SalesOrder $order): string
    {
        $normalized = Str::lower(trim((string) ($order->payment_method ?? '')));

        if ($normalized === '') {
            return 'Belum dipilih';
        }

        return match ($normalized) {
            'midtrans_snap' => 'Midtrans Snap',
            'bank_transfer' => 'Transfer Bank',
            'credit_card' => 'Kartu Kredit',
            'debit_card' => 'Kartu Debit',
            'echannel' => 'Mandiri Bill',
            'permata_va' => 'Permata Virtual Account',
            'bca_va' => 'BCA Virtual Account',
            'bni_va' => 'BNI Virtual Account',
            'bri_va' => 'BRI Virtual Account',
            'gopay' => 'GoPay',
            'shopeepay' => 'ShopeePay',
            'qris' => 'QRIS',
            default => (string) Str::of($normalized)->replace(['_', '-'], ' ')->title(),
        };
    }

    /**
     * @return array{number: ?string, url: ?string}
     */
    private function resolveTracking(SalesOrder $order): array
    {
        $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];
        $nestedTracking = is_array($metadata['tracking'] ?? null) ? $metadata['tracking'] : [];

        $trackingNumber = collect([
            Arr::get($metadata, 'tracking_number'),
            Arr::get($metadata, 'resi_number'),
            Arr::get($metadata, 'waybill'),
            Arr::get($metadata, 'awb'),
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
            Arr::get($metadata, 'resi_url'),
            Arr::get($nestedTracking, 'url'),
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

    /**
     * @return array<int, array{step: int, code: string, label: string}>
     */
    private function progressStages(): array
    {
        return collect(self::PROGRESS_STAGES)
            ->map(function (array $stage, int $step): array {
                return [
                    'step' => $step,
                    'code' => (string) ($stage['code'] ?? ''),
                    'label' => (string) ($stage['label'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function availableFilters(): array
    {
        return [
            ['key' => self::ORDER_FILTER_ALL, 'label' => 'Semua'],
            ['key' => self::ORDER_FILTER_ONGOING, 'label' => 'Berlangsung'],
            ['key' => self::ORDER_FILTER_SUCCESS, 'label' => 'Berhasil'],
            ['key' => self::ORDER_FILTER_CANCELLED, 'label' => 'Dibatalkan'],
        ];
    }
}
