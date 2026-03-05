<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncProductToJurnalJob;
use App\Models\Product;
use App\Services\Mekari\Jurnal\JurnalProductService;
use Illuminate\Console\Command;
use Throwable;

class SyncProductsToJurnal extends Command
{
    protected $signature = 'mekari:sync-products
        {--id= : Sync specific product by ID}
        {--queue : Dispatch sync into queue}
        {--chunk=100 : Chunk size for batch processing}';

    protected $description = 'Sync Entraverse products to Jurnal Mekari.';

    public function __construct(private readonly JurnalProductService $jurnalProduct)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productId = (string) $this->option('id');
        $useQueue = (bool) $this->option('queue');

        if ($productId !== '') {
            return $this->syncSingleProduct($productId, $useQueue);
        }

        return $this->syncAllProducts($useQueue);
    }

    protected function syncSingleProduct(string $productId, bool $useQueue): int
    {
        $product = Product::query()->with('category')->find($productId);
        if ($product === null) {
            $this->error("Product with ID {$productId} not found.");

            return self::FAILURE;
        }

        if ($useQueue) {
            SyncProductToJurnalJob::dispatch($product->id);
            $this->info("Product {$product->name} ({$product->id}) queued for sync.");

            return self::SUCCESS;
        }

        try {
            $this->jurnalProduct->syncProduct($product);
            $this->info("Product {$product->name} synced successfully.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error("Sync failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function syncAllProducts(bool $useQueue): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $query = Product::query()->with('category');
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No products found to sync.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failedCount = 0;

        $this->info("Starting sync for {$total} products".($useQueue ? ' (queue mode).' : '.'));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($chunkSize, function ($products) use ($useQueue, &$successCount, &$failedCount, $bar): void {
            foreach ($products as $product) {
                try {
                    if ($useQueue) {
                        SyncProductToJurnalJob::dispatch($product->id);
                    } else {
                        $this->jurnalProduct->syncProduct($product);
                    }

                    $successCount++;
                } catch (Throwable $exception) {
                    $failedCount++;
                    $this->newLine();
                    $this->error("Failed syncing {$product->id}: {$exception->getMessage()}");
                } finally {
                    $bar->advance();
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Sync finished. Success: {$successCount}, Failed: {$failedCount}.");

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
