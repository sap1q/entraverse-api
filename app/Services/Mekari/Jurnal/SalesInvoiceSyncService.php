<?php

declare(strict_types=1);

namespace App\Services\Mekari\Jurnal;

use App\Models\SalesOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class SalesInvoiceSyncService
{
    public function __construct(
        private readonly JurnalInvoiceService $jurnalInvoiceService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSalesOrderInvoice(SalesOrder $order): array
    {
        $order->loadMissing('items');

        if (Str::lower((string) $order->payment_status) !== 'settlement') {
            throw new RuntimeException('Sales invoice hanya disinkronkan untuk order settlement.');
        }

        $payload = $this->buildInvoicePayload($order);
        $response = $this->jurnalInvoiceService->createInvoice($payload);
        $invoiceId = $this->extractInvoiceId($response);

        $order->update([
            'jurnal_invoice_id' => $invoiceId,
            'jurnal_sync_status' => 'synced',
            'jurnal_sync_message' => null,
            'jurnal_synced_at' => now(),
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInvoicePayload(SalesOrder $order): array
    {
        $transactionDate = optional($order->settled_at ?? $order->created_at)?->toDateString() ?? now()->toDateString();

        $lines = $order->items
            ->map(function ($item): array {
                $lineName = trim((string) $item->product_name);
                $variantName = trim((string) ($item->variant_name ?? ''));
                $description = trim($variantName !== '' ? "{$lineName} - {$variantName}" : $lineName);

                return [
                    'description' => $description,
                    'quantity' => (int) $item->quantity,
                    'rate' => (float) $item->unit_price,
                ];
            })
            ->values()
            ->all();

        if ((float) $order->shipping_cost > 0) {
            $lines[] = [
                'description' => 'Biaya Pengiriman',
                'quantity' => 1,
                'rate' => (float) $order->shipping_cost,
            ];
        }

        return [
            'transaction_no' => (string) $order->order_number,
            'transaction_date' => $transactionDate,
            'due_date' => $transactionDate,
            'person_name' => (string) $order->customer_name,
            'memo' => (string) ($order->notes ?? ''),
            'transaction_lines_attributes' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractInvoiceId(array $response): ?string
    {
        $invoiceId = Arr::get($response, 'data.id')
            ?? Arr::get($response, 'invoice.id')
            ?? Arr::get($response, 'id');

        if (! is_scalar($invoiceId)) {
            return null;
        }

        $normalized = trim((string) $invoiceId);

        return $normalized !== '' ? $normalized : null;
    }
}

