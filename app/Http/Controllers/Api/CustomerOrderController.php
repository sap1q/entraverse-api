<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\MidtransService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        2 => ['code' => 'waiting_confirmation', 'label' => 'Dikonfirmasi'],
        3 => ['code' => 'order_shipped', 'label' => 'Pesanan Dikirim'],
        4 => ['code' => 'order_delivered', 'label' => 'Pesanan Terkirim'],
        5 => ['code' => 'completed', 'label' => 'Selesai'],
    ];

    public function __construct(
        private readonly MidtransService $midtransService,
        private readonly CheckoutService $checkoutService
    )
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
            ->with(['items.product', 'invoice'])
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
                ->map(fn (SalesOrder $order): array => $this->transformOrder($this->syncPendingPayment($order)))
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
            ->with(['items.product', 'invoice'])
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        $order = $this->syncPendingPayment($order);

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
            ->with('invoice')
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        $order = $this->syncPendingPayment($order);
        $paymentStatus = strtolower(trim((string) ($order->payment_status ?? 'pending')));
        if (! in_array($paymentStatus, self::PAYMENT_PENDING_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini tidak memerlukan pembayaran ulang.',
            ], 422);
        }

        $paymentPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $invoice = $order->invoice;
        $snap = is_array($paymentPayload['snap'] ?? null) ? $paymentPayload['snap'] : [];
        $snapToken = trim((string) ($invoice?->snap_token ?? $order->snap_token ?? ''));
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
                    'invoice_number' => $invoice?->invoice_number,
                    'payment_status' => (string) ($order->payment_status ?? 'pending'),
                    'status' => (string) $order->status,
                    'status_step' => $this->resolveStatusMetadata($order)['step'],
                    'total_amount' => (float) $order->total_amount,
                ],
                'snap_token' => $snapToken !== '' ? $snapToken : null,
                'snap_redirect_url' => $snapRedirectUrl !== '' ? $snapRedirectUrl : null,
                'midtrans_client_key' => $this->midtransService->clientKey(),
                'midtrans_snap_js_url' => $this->midtransService->snapJsUrl(),
                'payment_details' => $this->resolvePaymentDetails($order),
            ],
        ]);
    }

    public function received(Request $request, string $orderId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $order = DB::transaction(function () use ($user, $orderId): ?SalesOrder {
                $order = SalesOrder::query()
                    ->with(['items.product', 'invoice'])
                    ->where('user_id', $user->id)
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    return null;
                }

                if ((string) $order->status === 'selesai') {
                    return $order;
                }

                if (! $this->canConfirmReceived($order)) {
                    throw ValidationException::withMessages([
                        'order' => ['Pesanan belum dapat dikonfirmasi sebagai diterima. Tunggu sampai status pengiriman aktif terlebih dahulu.'],
                    ]);
                }

                $metadata = is_array($order->shipping_metadata) ? $order->shipping_metadata : [];
                $metadata['received_at'] = now()->toISOString();
                $metadata['received_by'] = 'customer';

                $order->status = 'selesai';
                $order->shipping_metadata = $metadata;
                $order->save();

                return $order->fresh(['items.product', 'invoice']);
            });

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pesanan tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dikonfirmasi telah diterima.',
                'data' => $this->transformOrder($order),
                'status_catalog' => [
                    'steps' => $this->progressStages(),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?? 'Konfirmasi pesanan diterima gagal diproses.',
                'errors' => $exception->errors(),
            ], 422);
        }
    }

    private function transformOrder(SalesOrder $order): array
    {
        $statusMetadata = $this->resolveStatusMetadata($order);
        $tracking = $this->resolveTracking($order);
        $firstItem = $order->items->first();
        $paymentDetails = $this->resolvePaymentDetails($order);
        $canConfirmReceived = $this->canConfirmReceived($order);

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
            'invoice_number' => $order->invoice?->invoice_number,
            'payment_details' => $paymentDetails,
            'can_resume_payment' => (bool) ($paymentDetails['can_resume_payment'] ?? false),
            'can_confirm_received' => $canConfirmReceived,
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

    private function canConfirmReceived(SalesOrder $order): bool
    {
        $status = Str::lower(trim((string) $order->status));
        $paymentStatus = Str::lower(trim((string) ($order->payment_status ?? 'pending')));

        if (! in_array($status, ['dikirim', 'terkirim'], true)) {
            return false;
        }

        return in_array($paymentStatus, self::PAYMENT_SUCCESS_STATUSES, true);
    }

    private function syncPendingPayment(SalesOrder $order): SalesOrder
    {
        try {
            return $this->checkoutService->syncPendingPaymentStatus($order);
        } catch (Throwable) {
            return $order->fresh(['items.product', 'invoice']) ?? $order;
        }
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

        if ($status === 'diproses') {
            $stage = self::PROGRESS_STAGES[2];

            return [
                'stage_code' => (string) $stage['code'],
                'step' => 2,
                'label' => 'Diproses',
                'group' => self::ORDER_FILTER_ONGOING,
                'can_track_package' => false,
            ];
        }

        if (in_array($paymentStatus, self::PAYMENT_SUCCESS_STATUSES, true)) {
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
        return $this->resolvePaymentMethodLabelFromCode(Str::lower(trim((string) ($order->payment_method ?? ''))));
    }

    private function resolvePaymentMethodLabelFromCode(string $normalized): string
    {
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
            'indomaret' => 'Indomaret',
            'alfamart' => 'Alfamart',
            default => (string) Str::of($normalized)->replace(['_', '-'], ' ')->title(),
        };
    }

    /**
     * @return array{
     *   status_label: string,
     *   is_pending: bool,
     *   payment_type: ?string,
     *   method_code: ?string,
     *   method_label: string,
     *   channel_code: ?string,
     *   channel_label: ?string,
     *   account_label: ?string,
     *   account_number: ?string,
     *   secondary_label: ?string,
     *   secondary_value: ?string,
     *   expiry_time: ?string,
     *   total_amount: float,
     *   pdf_url: ?string,
     *   status_message: ?string,
     *   can_resume_payment: bool,
     *   instructions: array<int, string>
     * }
     */
    private function resolvePaymentDetails(SalesOrder $order): array
    {
        $paymentPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $callback = is_array($paymentPayload['callback'] ?? null) ? $paymentPayload['callback'] : [];
        $invoice = $order->invoice;
        $invoiceStatus = Str::lower(trim((string) ($invoice?->payment_status ?? '')));
        $paymentStatus = $invoiceStatus !== '' ? $invoiceStatus : Str::lower(trim((string) ($order->payment_status ?? 'pending')));
        $invoiceMethod = $this->sanitizeNullableString($invoice?->payment_method);
        $paymentType = Str::lower(trim((string) ($callback['payment_type'] ?? $order->payment_method ?? '')));
        if ($paymentType === '' || $paymentType === 'midtrans_snap') {
            $paymentType = $this->inferPaymentTypeFromInvoiceMethod($invoiceMethod) ?? $paymentType;
        }

        $channelCode = $this->resolvePaymentChannelCode($paymentType, $callback)
            ?? $this->inferPaymentChannelCodeFromMethodLabel($invoiceMethod);
        $channelLabel = $this->resolvePaymentChannelLabel($channelCode);
        $methodCode = $this->resolvePaymentMethodCode($paymentType, $channelCode)
            ?? $this->inferPaymentMethodCodeFromMethodLabel($invoiceMethod, $channelCode);
        $account = $this->resolvePaymentAccountInfo(
            paymentType: $paymentType,
            callback: $callback,
            invoiceVaNumber: $this->sanitizeNullableString($invoice?->payment_va_number),
            invoiceBillKey: $this->sanitizeNullableString($invoice?->payment_bill_key)
        );
        $instructions = $this->resolvePaymentInstructions(
            paymentType: $paymentType,
            channelLabel: $channelLabel,
            accountLabel: $account['label'],
            secondaryLabel: $account['secondary_label']
        );
        $pdfUrl = collect([
            Arr::get($callback, 'pdf_url'),
            Arr::get($paymentPayload, 'pdf_url'),
            Arr::get($paymentPayload, 'snap.pdf_url'),
        ])->map(
            fn ($value): string => is_string($value) ? trim($value) : ''
        )->first(
            fn (string $value): bool => $value !== '' && Str::startsWith($value, ['http://', 'https://']),
            null
        );

        $statusLabel = match (true) {
            in_array($paymentStatus, self::PAYMENT_SUCCESS_STATUSES, true) => 'Pembayaran Berhasil',
            in_array($paymentStatus, self::PAYMENT_FAILED_STATUSES, true) => 'Pembayaran Gagal',
            default => 'Menunggu Pembayaran',
        };

        $snapToken = trim((string) ($invoice?->snap_token ?? $order->snap_token ?? ''));
        $snapRedirectUrl = trim((string) Arr::get($paymentPayload, 'snap.redirect_url', ''));

        return [
            'status_label' => $statusLabel,
            'is_pending' => in_array($paymentStatus, self::PAYMENT_PENDING_STATUSES, true),
            'payment_type' => $paymentType !== '' ? $paymentType : null,
            'method_code' => $methodCode,
            'method_label' => $invoiceMethod ?? $this->resolvePaymentMethodLabelFromCode($methodCode ?? $paymentType),
            'channel_code' => $channelCode,
            'channel_label' => $channelLabel,
            'account_label' => $account['label'],
            'account_number' => $account['value'],
            'secondary_label' => $account['secondary_label'],
            'secondary_value' => $account['secondary_value'],
            'expiry_time' => $invoice?->expiry_time?->toISOString() ?? $this->normalizePaymentDate(
                Arr::get($callback, 'expiry_time')
                    ?? Arr::get($callback, 'settlement_time')
                    ?? Arr::get($callback, 'transaction_time')
            ),
            'total_amount' => $invoice !== null ? (float) $invoice->amount_total : (float) $order->total_amount,
            'pdf_url' => $pdfUrl,
            'status_message' => $this->sanitizeNullableString(
                Arr::get($callback, 'status_message')
                    ?? Arr::get($paymentPayload, 'status_message')
            ),
            'can_resume_payment' => in_array($paymentStatus, self::PAYMENT_PENDING_STATUSES, true)
                && ($snapToken !== '' || $snapRedirectUrl !== ''),
            'instructions' => $instructions,
        ];
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function resolvePaymentChannelCode(string $paymentType, array $callback): ?string
    {
        $vaBank = $this->sanitizeNullableString(Arr::get($callback, 'va_numbers.0.bank'));
        if ($vaBank !== null) {
            return Str::lower($vaBank);
        }

        if ($this->sanitizeNullableString(Arr::get($callback, 'permata_va_number')) !== null) {
            return 'permata';
        }

        if ($paymentType === 'echannel') {
            return 'mandiri';
        }

        $store = $this->sanitizeNullableString(Arr::get($callback, 'store'));
        if ($store !== null) {
            return Str::lower($store);
        }

        if (Str::endsWith($paymentType, '_va')) {
            return Str::before($paymentType, '_va');
        }

        return match ($paymentType) {
            'gopay', 'shopeepay', 'qris', 'credit_card', 'debit_card' => $paymentType,
            default => null,
        };
    }

    private function resolvePaymentChannelLabel(?string $channelCode): ?string
    {
        if ($channelCode === null || trim($channelCode) === '') {
            return null;
        }

        return match (Str::lower($channelCode)) {
            'bca' => 'BCA',
            'bni' => 'BNI',
            'bri' => 'BRI',
            'permata' => 'Permata Bank',
            'mandiri' => 'Mandiri',
            'gopay' => 'GoPay',
            'shopeepay' => 'ShopeePay',
            'qris' => 'QRIS',
            'credit_card' => 'Kartu Kredit',
            'debit_card' => 'Kartu Debit',
            'indomaret' => 'Indomaret',
            'alfamart' => 'Alfamart',
            default => (string) Str::of($channelCode)->replace(['_', '-'], ' ')->title(),
        };
    }

    private function resolvePaymentMethodCode(string $paymentType, ?string $channelCode): ?string
    {
        if ($paymentType === '') {
            return null;
        }

        if ($paymentType === 'bank_transfer' && $channelCode !== null) {
            return sprintf('%s_va', Str::lower($channelCode));
        }

        return $paymentType;
    }

    private function inferPaymentTypeFromInvoiceMethod(?string $invoiceMethod): ?string
    {
        if ($invoiceMethod === null) {
            return null;
        }

        $normalized = Str::lower(trim($invoiceMethod));
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            Str::contains($normalized, 'virtual account') => 'bank_transfer',
            Str::contains($normalized, 'mandiri bill') => 'echannel',
            Str::contains($normalized, 'gopay') => 'gopay',
            Str::contains($normalized, 'shopeepay') => 'shopeepay',
            Str::contains($normalized, 'qris') => 'qris',
            Str::contains($normalized, 'kartu kredit') => 'credit_card',
            Str::contains($normalized, 'kartu debit') => 'debit_card',
            Str::contains($normalized, ['indomaret', 'alfamart']) => 'cstore',
            default => null,
        };
    }

    private function inferPaymentChannelCodeFromMethodLabel(?string $invoiceMethod): ?string
    {
        if ($invoiceMethod === null) {
            return null;
        }

        $normalized = Str::lower(trim($invoiceMethod));
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            Str::contains($normalized, 'bca') => 'bca',
            Str::contains($normalized, 'bni') => 'bni',
            Str::contains($normalized, 'bri') => 'bri',
            Str::contains($normalized, 'permata') => 'permata',
            Str::contains($normalized, 'mandiri') => 'mandiri',
            Str::contains($normalized, 'gopay') => 'gopay',
            Str::contains($normalized, 'shopeepay') => 'shopeepay',
            Str::contains($normalized, 'qris') => 'qris',
            Str::contains($normalized, 'kartu kredit') => 'credit_card',
            Str::contains($normalized, 'kartu debit') => 'debit_card',
            Str::contains($normalized, 'indomaret') => 'indomaret',
            Str::contains($normalized, 'alfamart') => 'alfamart',
            default => null,
        };
    }

    private function inferPaymentMethodCodeFromMethodLabel(?string $invoiceMethod, ?string $channelCode): ?string
    {
        $paymentType = $this->inferPaymentTypeFromInvoiceMethod($invoiceMethod);
        if ($paymentType === null) {
            return null;
        }

        return $this->resolvePaymentMethodCode($paymentType, $channelCode);
    }

    /**
     * @param array<string, mixed> $callback
     * @return array{
     *   label: ?string,
     *   value: ?string,
     *   secondary_label: ?string,
     *   secondary_value: ?string
     * }
     */
    private function resolvePaymentAccountInfo(
        string $paymentType,
        array $callback,
        ?string $invoiceVaNumber = null,
        ?string $invoiceBillKey = null
    ): array
    {
        if ($paymentType === 'echannel') {
            return [
                'label' => 'Bill Key',
                'value' => $invoiceBillKey ?? $this->sanitizeNullableString(Arr::get($callback, 'bill_key')),
                'secondary_label' => 'Biller Code',
                'secondary_value' => $this->sanitizeNullableString(Arr::get($callback, 'biller_code')),
            ];
        }

        if (in_array($paymentType, ['cstore', 'indomaret', 'alfamart'], true)) {
            return [
                'label' => 'Kode Pembayaran',
                'value' => $invoiceVaNumber ?? $this->sanitizeNullableString(Arr::get($callback, 'payment_code')),
                'secondary_label' => 'Gerai',
                'secondary_value' => $this->sanitizeNullableString(Arr::get($callback, 'store')),
            ];
        }

        $virtualAccount = $invoiceVaNumber
            ?? $this->sanitizeNullableString(Arr::get($callback, 'va_numbers.0.va_number'))
            ?? $this->sanitizeNullableString(Arr::get($callback, 'permata_va_number'));

        if ($virtualAccount !== null || $paymentType === 'bank_transfer' || Str::endsWith($paymentType, '_va')) {
            return [
                'label' => 'Nomor Virtual Account',
                'value' => $virtualAccount,
                'secondary_label' => null,
                'secondary_value' => null,
            ];
        }

        return [
            'label' => null,
            'value' => null,
            'secondary_label' => null,
            'secondary_value' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePaymentInstructions(
        string $paymentType,
        ?string $channelLabel,
        ?string $accountLabel,
        ?string $secondaryLabel
    ): array {
        $provider = $channelLabel ?? 'penyedia pembayaran';
        $normalizedType = Str::lower($paymentType);

        if ($normalizedType === 'echannel') {
            return [
                'Buka Livin atau ATM Mandiri lalu pilih menu bayar atau multipayment.',
                sprintf('Masukkan %s yang tertera pada pesanan Anda.', $secondaryLabel ?? 'Biller Code'),
                sprintf('Masukkan %s yang tertera pada pesanan Anda.', $accountLabel ?? 'Bill Key'),
                'Verifikasi total tagihan lalu selesaikan pembayaran sebelum batas waktu berakhir.',
            ];
        }

        if ($normalizedType === 'bank_transfer' || Str::endsWith($normalizedType, '_va')) {
            return [
                sprintf('Buka aplikasi mobile banking, internet banking, atau ATM %s.', $provider),
                'Pilih menu transfer atau bayar ke Virtual Account.',
                sprintf('Masukkan %s persis seperti yang tertera pada pesanan.', Str::lower($accountLabel ?? 'Nomor Virtual Account')),
                'Periksa kembali total tagihan, lalu selesaikan pembayaran sebelum batas waktu berakhir.',
            ];
        }

        if (in_array($normalizedType, ['gopay', 'shopeepay', 'qris'], true)) {
            return [
                sprintf('Buka aplikasi %s.', $provider),
                'Ikuti instruksi pembayaran yang muncul pada halaman transaksi Anda.',
                'Selesaikan pembayaran sebelum batas waktu berakhir agar pesanan tidak dibatalkan otomatis.',
            ];
        }

        if (in_array($normalizedType, ['cstore', 'indomaret', 'alfamart'], true)) {
            return [
                sprintf('Datangi gerai %s terdekat.', $provider),
                sprintf('Tunjukkan atau sebutkan %s ke kasir.', Str::lower($accountLabel ?? 'Kode Pembayaran')),
                'Selesaikan pembayaran sesuai total tagihan sebelum batas waktu berakhir.',
            ];
        }

        return [
            'Gunakan data pembayaran yang tertera pada pesanan Anda untuk menyelesaikan transaksi.',
            'Pastikan total tagihan sesuai dan lakukan pembayaran sebelum batas waktu berakhir.',
        ];
    }

    private function normalizePaymentDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
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
