<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductToJurnalJob;
use App\Models\Product;
use App\Services\Mekari\Exceptions\MekariApiException;
use App\Services\Mekari\Jurnal\JurnalProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class JurnalSyncController extends Controller
{
    public function __construct(private readonly JurnalProductService $jurnalProduct) {}

    public function syncProduct(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->with('category')->findOrFail($id);
        $shouldQueue = $request->boolean('queue') || $request->boolean('async');

        if ($shouldQueue) {
            SyncProductToJurnalJob::dispatch($product->id);

            return response()->json([
                'success' => true,
                'message' => 'Product sync has been queued.',
                'data' => [
                    'product_id' => $product->id,
                ],
            ], 202);
        }

        try {
            $response = $this->jurnalProduct->syncProduct($product);

            return response()->json([
                'success' => true,
                'message' => 'Product synced to Jurnal.',
                'data' => [
                    'product' => $product->fresh(),
                    'jurnal_response' => $response,
                ],
            ]);
        } catch (MekariApiException $exception) {
            Log::warning('Jurnal single product sync failed.', [
                'product_id' => $product->id,
                'status' => $exception->getStatusCode(),
                'error' => $exception->getMessage(),
                'response' => $exception->getResponseBody(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync product to Jurnal.',
                'error' => $exception->getMessage(),
                'data' => $exception->getResponseBody(),
                'source' => 'mekari',
                'upstream_status' => $exception->getStatusCode(),
            ], $this->resolveClientStatusFromMekari($exception));
        } catch (Throwable $exception) {
            Log::error('Unexpected error while syncing single product to Jurnal.', [
                'product_id' => $product->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unexpected error while syncing product.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function syncAllProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['uuid'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $shouldQueue = (bool) ($validated['queue'] ?? false);

        $query = Product::query()->with('category');
        if (! empty($validated['product_ids'])) {
            $query->whereIn('id', $validated['product_ids']);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No products available to sync.',
                'data' => [
                    'total' => 0,
                    'success_count' => 0,
                    'failed_count' => 0,
                    'queued_count' => 0,
                    'success' => [],
                    'failed' => [],
                ],
            ]);
        }

        if ($shouldQueue) {
            $queuedIds = $products->pluck('id')->all();
            foreach ($queuedIds as $productId) {
                SyncProductToJurnalJob::dispatch((string) $productId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch sync has been queued.',
                'data' => [
                    'total' => count($queuedIds),
                    'queued_count' => count($queuedIds),
                    'queued_product_ids' => $queuedIds,
                ],
            ], 202);
        }

        $results = $this->syncProductsDirectly($products);

        return response()->json([
            'success' => true,
            'message' => 'Batch sync completed.',
            'data' => [
                'total' => $products->count(),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                ...$results,
            ],
        ]);
    }

    public function getJurnalProducts(Request $request): JsonResponse
    {
        try {
            $response = $this->jurnalProduct->getProducts($request->query());

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);
        } catch (MekariApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products from Jurnal.',
                'error' => $exception->getMessage(),
                'data' => $exception->getResponseBody(),
                'source' => 'mekari',
                'upstream_status' => $exception->getStatusCode(),
            ], $this->resolveClientStatusFromMekari($exception));
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Unexpected error while fetching Jurnal products.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function importJurnalProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_archive' => ['nullable', 'boolean'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $params = array_filter([
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 50,
            'include_archive' => $validated['include_archive'] ?? true,
        ], static fn ($value) => $value !== null);

        $maxPages = (int) ($validated['max_pages'] ?? 1);

        try {
            $result = $this->jurnalProduct->importProductsFromJurnal($params, $maxPages);

            return response()->json([
                'success' => true,
                'message' => 'Jurnal products imported successfully.',
                'data' => $result,
            ]);
        } catch (MekariApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import products from Jurnal.',
                'error' => $exception->getMessage(),
                'data' => $exception->getResponseBody(),
                'source' => 'mekari',
                'upstream_status' => $exception->getStatusCode(),
            ], $this->resolveClientStatusFromMekari($exception));
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Unexpected error while importing products from Jurnal.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function archiveProduct(string $id): JsonResponse
    {
        $product = Product::query()->with('category')->findOrFail($id);

        try {
            $response = $this->jurnalProduct->archiveProduct($product);

            return response()->json([
                'success' => true,
                'message' => 'Product archived in Jurnal.',
                'data' => [
                    'product' => $product->fresh(),
                    'jurnal_response' => $response,
                ],
            ]);
        } catch (MekariApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive product in Jurnal.',
                'error' => $exception->getMessage(),
                'data' => $exception->getResponseBody(),
                'source' => 'mekari',
                'upstream_status' => $exception->getStatusCode(),
            ], $this->resolveClientStatusFromMekari($exception));
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Unexpected error while archiving product.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function unarchiveProduct(string $id): JsonResponse
    {
        $product = Product::query()->with('category')->findOrFail($id);

        try {
            $response = $this->jurnalProduct->unarchiveProduct($product);

            return response()->json([
                'success' => true,
                'message' => 'Product unarchived in Jurnal.',
                'data' => [
                    'product' => $product->fresh(),
                    'jurnal_response' => $response,
                ],
            ]);
        } catch (MekariApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unarchive product in Jurnal.',
                'error' => $exception->getMessage(),
                'data' => $exception->getResponseBody(),
                'source' => 'mekari',
                'upstream_status' => $exception->getStatusCode(),
            ], $this->resolveClientStatusFromMekari($exception));
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Unexpected error while unarchiving product.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        if (! $this->isValidWebhookSignature($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        Log::info('Mekari webhook received.', [
            'event' => $request->input('event'),
            'payload' => $request->all(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{
     *     success: array<int, array{id: string, name: string, jurnal_id: string|null}>,
     *     failed: array<int, array{id: string, name: string, error: string}>
     * }
     */
    protected function syncProductsDirectly(Collection $products): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($products as $product) {
            try {
                $this->jurnalProduct->syncProduct($product);

                $results['success'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'jurnal_id' => $product->fresh()?->jurnal_id,
                ];
            } catch (Throwable $exception) {
                $results['failed'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'error' => $exception->getMessage(),
                ];

                Log::warning('Failed syncing product in batch operation.', [
                    'product_id' => $product->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }

    protected function isValidWebhookSignature(Request $request): bool
    {
        $secret = (string) config('services.mekari.webhook_secret', '');
        if ($secret === '') {
            return true;
        }

        $providedSignature = (string) $request->header('X-Mekari-Signature', $request->header('X-Hub-Signature-256', ''));
        if ($providedSignature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $request->getContent(), $secret);
        $normalizedProvided = strtolower(str_replace('sha256=', '', $providedSignature));

        return hash_equals($computed, $normalizedProvided);
    }

    protected function resolveClientStatusFromMekari(MekariApiException $exception): int
    {
        $upstreamStatus = $exception->getStatusCode();

        // 401/403 dari Mekari adalah auth upstream, bukan auth user aplikasi.
        if (in_array($upstreamStatus, [401, 403], true)) {
            return 502;
        }

        return $upstreamStatus > 0 ? $upstreamStatus : 502;
    }
}
