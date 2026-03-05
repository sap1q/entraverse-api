<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\Mekari\Jurnal\JurnalProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncProductToJurnalJob implements ShouldBeUnique, ShouldQueue
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

    public int $uniqueFor = 900;

    public function __construct(public string $productId) {}

    public function uniqueId(): string
    {
        return $this->productId;
    }

    public function handle(JurnalProductService $jurnalProduct): void
    {
        $product = Product::query()
            ->with('category')
            ->find($this->productId);

        if ($product === null) {
            Log::warning('Skipping product sync because product not found.', [
                'product_id' => $this->productId,
            ]);

            return;
        }

        $response = $jurnalProduct->syncProduct($product);

        Log::info('Product synced to Jurnal via queue job.', [
            'product_id' => $product->id,
            'jurnal_id' => $product->fresh()?->jurnal_id,
            'response_code' => $response['code'] ?? null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queue job failed syncing product to Jurnal.', [
            'product_id' => $this->productId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }
}
