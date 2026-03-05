<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ProductSyncedToJurnal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class HandleProductSync implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function handle(ProductSyncedToJurnal $event): void
    {
        Log::info('Product sync event handled.', [
            'product_id' => $event->product->id,
            'jurnal_id' => $event->product->jurnal_id,
            'response_status' => $event->jurnalResponse['status'] ?? null,
        ]);
    }
}
