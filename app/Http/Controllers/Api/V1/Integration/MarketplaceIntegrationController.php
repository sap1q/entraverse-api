<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Marketplace\MarketplaceIntegrationException;
use App\Services\Marketplace\MarketplaceIntegrationService;
use App\Support\ProductVariantKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketplaceIntegrationController extends Controller
{
    public function __construct(private readonly MarketplaceIntegrationService $marketplace) {}

    public function connections(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->marketplace->listConnections(),
        ]);
    }

    public function connect(Request $request, string $channel): JsonResponse
    {
        try {
            $authorizationUrl = $this->marketplace->beginAuthorization(
                $channel,
                $request->input('redirect_path')
            );

            return response()->json([
                'success' => true,
                'message' => 'Authorization URL generated.',
                'data' => [
                    'authorization_url' => $authorizationUrl,
                ],
            ]);
        } catch (MarketplaceIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }

    public function callback(Request $request, string $channel): RedirectResponse
    {
        return redirect()->away(
            $this->marketplace->finalizeAuthorization($channel, $request->all())
        );
    }

    public function disconnect(string $channel): JsonResponse
    {
        try {
            $connection = $this->marketplace->disconnect($channel);

            return response()->json([
                'success' => true,
                'message' => 'Marketplace connection disconnected.',
                'data' => [
                    'channel' => $connection->channel,
                    'status' => $connection->status,
                ],
            ]);
        } catch (MarketplaceIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }

    public function sync(Request $request, string $channel): JsonResponse
    {
        try {
            $result = $this->marketplace->sync($channel);

            return response()->json([
                'success' => true,
                'message' => 'Marketplace sync started.',
                'data' => $result,
            ]);
        } catch (MarketplaceIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }

    public function storeMapping(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variant_id' => ['required', 'string', 'max:160'],
            'seller_sku' => ['nullable', 'string', 'max:160'],
            'marketplace_product_id' => ['nullable', 'string', 'max:160'],
            'marketplace_sku_id' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $product = Product::query()->findOrFail($validated['product_id']);
            $variantKey = ProductVariantKey::resolve([
                'id' => $validated['variant_id'],
                'sku_seller' => $validated['seller_sku'] ?? null,
            ]);

            $mapping = $this->marketplace->upsertMapping(
                $channel,
                $product,
                $variantKey,
                $validated['seller_sku'] ?? null,
                $validated['marketplace_product_id'] ?? null,
                $validated['marketplace_sku_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Marketplace mapping saved.',
                'data' => [
                    'id' => $mapping->id,
                    'channel' => $mapping->channel,
                    'variant_key' => $mapping->variant_key,
                    'status' => $mapping->status,
                ],
            ]);
        } catch (MarketplaceIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }

    public function destroyMapping(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variant_id' => ['required', 'string', 'max:160'],
        ]);

        try {
            $product = Product::query()->findOrFail($validated['product_id']);
            $variantKey = ProductVariantKey::resolve([
                'id' => $validated['variant_id'],
            ]);

            $this->marketplace->deleteMapping($channel, $product, $variantKey);

            return response()->json([
                'success' => true,
                'message' => 'Marketplace mapping removed.',
            ]);
        } catch (MarketplaceIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }
}
