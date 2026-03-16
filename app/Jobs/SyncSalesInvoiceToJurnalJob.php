<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SalesOrder;
use App\Services\Mekari\Jurnal\SalesInvoiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSalesInvoiceToJurnalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 180, 300, 600];

    public function __construct(public string $salesOrderId) {}

    public function handle(SalesInvoiceSyncService $invoiceSyncService): void
    {
        $order = SalesOrder::query()->with('items')->find($this->salesOrderId);
        if (! $order) {
            Log::warning('Skip jurnal sync invoice because order not found.', [
                'sales_order_id' => $this->salesOrderId,
            ]);

            return;
        }

        if ((string) ($order->jurnal_sync_status ?? '') === 'synced' && $order->jurnal_invoice_id) {
            Log::info('Skip jurnal sync invoice because already synced.', [
                'sales_order_id' => $this->salesOrderId,
                'jurnal_invoice_id' => $order->jurnal_invoice_id,
            ]);

            return;
        }

        $invoiceSyncService->syncSalesOrderInvoice($order);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queue job failed syncing sales invoice to Jurnal.', [
            'sales_order_id' => $this->salesOrderId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        SalesOrder::query()
            ->whereKey($this->salesOrderId)
            ->update([
                'jurnal_sync_status' => 'failed',
                'jurnal_sync_message' => $exception->getMessage(),
            ]);
    }
}

