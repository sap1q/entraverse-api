<?php

namespace App\Http\Resources;

use App\Services\Pricing\PricingCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inventory = is_array($this->inventory) ? $this->inventory : [];
        $variants = is_array($this->variants) ? $this->variants : [];
        $variantPricing = is_array($this->variant_pricing) ? $this->variant_pricing : [];
        $variantPricingItems = array_is_list($variantPricing)
            ? $variantPricing
            : (is_array($variantPricing['items'] ?? null) ? $variantPricing['items'] : []);

        $normalizedPricing = collect($variantPricingItems)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $warehouseStock = [];
                if (is_array($item['warehouse_stock'] ?? null)) {
                    foreach ($item['warehouse_stock'] as $warehouse => $qty) {
                        $warehouseName = trim(is_string($warehouse) ? $warehouse : '');
                        if ($warehouseName === '') {
                            continue;
                        }

                        $warehouseStock[$warehouseName] = (int) $qty;
                    }
                }

                if ($warehouseStock === []) {
                    $fallbackWarehouse = trim((string) ($item['warehouse'] ?? 'Gudang Utama'));
                    if ($fallbackWarehouse === '') {
                        $fallbackWarehouse = 'Gudang Utama';
                    }

                    $warehouseStock[$fallbackWarehouse] = (int) ($item['stock'] ?? 0);
                }

                $item['warehouse_stock'] = $warehouseStock;
                $item['warehouse'] = (string) (array_key_first($warehouseStock) ?? 'Gudang Utama');
                $item['stock'] = (int) collect($warehouseStock)->sum();
                $item['purchase_price'] = (float) ($item['purchase_price'] ?? 0);
                $item['purchase_price_idr'] = (float) ($item['purchase_price_idr'] ?? 0);
                $item['tiktok_price'] = (float) ($item['tiktok_price'] ?? ($item['tokopedia_price'] ?? 0));
                $item['tiktok_fee'] = (float) ($item['tiktok_fee'] ?? ($item['tokopedia_fee'] ?? 0));
                return $item;
            })
            ->values();

        $stockFromPricing = (int) $normalizedPricing->sum(fn (array $item) => (int) ($item['stock'] ?? 0));
        $totalStock = isset($inventory['total_stock'])
            ? (int) $inventory['total_stock']
            : (isset($this->stock) ? (int) $this->stock : $stockFromPricing);
        $price = (float) ($inventory['price'] ?? 0);
        $weight = (int) ($inventory['weight'] ?? 0);

        $photoItems = is_array($this->photos) ? $this->photos : [];
        $normalizedPhotos = collect($photoItems)
            ->map(function ($photo) {
                $url = null;
                $alt = null;
                $isPrimary = false;

                if (is_string($photo) && trim($photo) !== '') {
                    $url = $photo;
                } elseif (is_array($photo)) {
                    $url = is_string($photo['url'] ?? null) ? $photo['url'] : null;
                    $alt = is_string($photo['alt'] ?? null) ? $photo['alt'] : null;
                    $isPrimary = (bool) ($photo['is_primary'] ?? false);
                }

                if (! is_string($url) || trim($url) === '') {
                    return null;
                }

                $normalizedUrl = trim($url);
                if (! Str::startsWith($normalizedUrl, ['http://', 'https://', '/'])) {
                    $normalizedUrl = '/storage/products/' . ltrim($normalizedUrl, '/');
                } elseif (Str::startsWith($normalizedUrl, 'storage/')) {
                    $normalizedUrl = '/' . ltrim($normalizedUrl, '/');
                }

                $absoluteUrl = Str::startsWith($normalizedUrl, ['http://', 'https://'])
                    ? $normalizedUrl
                    : url($normalizedUrl);

                return [
                    'url' => $absoluteUrl,
                    'alt' => $alt,
                    'is_primary' => $isPrimary,
                ];
            })
            ->filter()
            ->take(5)
            ->values();

        $mainImage = $normalizedPhotos->firstWhere('is_primary', true) ?? $normalizedPhotos->first();
        $priceBreakdown = app(PricingCalculator::class)->fromProduct($this->resource)->toArray();

        return [
            'id' => (string) $this->id,
            'uuid' => (string) $this->id,
            'name' => $this->name,
            'slug' => Str::slug((string) $this->name),
            'brand' => $this->brand,
            'category' => $this->category,
            'category_id' => $this->category_id ? (string) $this->category_id : null,
            'description' => $this->description,
            'spu' => $this->spu,
            'price' => $price,
            'formatted_price' => 'Rp ' . number_format($price, 0, ',', '.'),
            'stock' => $totalStock,
            'is_in_stock' => $totalStock > 0,
            'inventory' => [
                'price' => $price,
                'weight' => $weight,
                'total_stock' => $totalStock,
                ...$inventory,
            ],
            'price_breakdown' => $priceBreakdown,
            'variants' => $variants,
            'variant_pricing' => $normalizedPricing->all(),
            'photos' => $normalizedPhotos->all(),
            'main_image' => $mainImage['url'] ?? null,
            'trade_in' => (bool) $this->trade_in,
            'product_status' => $this->product_status,
            'status' => $this->product_status,
            'jurnal_id' => $this->jurnal_id,
            'jurnal_metadata' => $this->jurnal_metadata,
            'mekari_status' => $this->mekari_status,
            'last_synced_at' => optional($this->last_synced_at)?->toISOString(),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
