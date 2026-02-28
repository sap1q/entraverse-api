<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photos = is_array($this->photos) ? $this->photos : [];
        $images = is_array($this->images) ? $this->images : [];
        $media = ! empty($photos) ? $photos : $images;
        $inventory = is_array($this->inventory) ? $this->inventory : [];
        $variants = is_array($this->variants) ? $this->variants : [];
        $variantPricing = is_array($this->variant_pricing) ? $this->variant_pricing : [];
        $variantPricingItems = [];
        if (isset($variantPricing['items']) && is_array($variantPricing['items'])) {
            $variantPricingItems = $variantPricing['items'];
        } elseif (array_is_list($variantPricing)) {
            $variantPricingItems = $variantPricing;
        }

        $price = (float) ($inventory['price'] ?? ($this->price ?? 0));
        $discountedPrice = isset($this->discounted_price) ? (float) $this->discounted_price : null;
        $calculatedVariantStock = (int) collect($variantPricingItems)->sum(
            fn ($item) => (int) (is_array($item) ? ($item['stock'] ?? 0) : 0)
        );
        $stock = isset($inventory['total_stock'])
            ? (int) $inventory['total_stock']
            : (isset($this->stock) ? (int) $this->stock : $calculatedVariantStock);
        $isInStock = $stock > 0;
        $stockLabel = $stock <= 0 ? 'Out of Stock' : ($stock < 5 ? 'Limited Stock' : 'In Stock');

        $toAbsoluteUrl = static function (?string $path): ?string {
            if (! is_string($path) || trim($path) === '') {
                return null;
            }

            if (Str::startsWith($path, ['http://', 'https://'])) {
                return $path;
            }

            return url($path);
        };

        $normalizedImages = collect($media)
            ->map(function ($image) use ($toAbsoluteUrl) {
                if (is_string($image)) {
                    return [
                        'url' => $toAbsoluteUrl($image),
                        'alt' => null,
                        'is_primary' => false,
                    ];
                }

                if (is_array($image)) {
                    return [
                        'url' => $toAbsoluteUrl($image['url'] ?? null),
                        'alt' => $image['alt'] ?? null,
                        'is_primary' => (bool) ($image['is_primary'] ?? false),
                    ];
                }

                return null;
            })
            ->filter(fn ($image) => is_array($image) && ! empty($image['url']))
            ->values();

        $mainImage = $normalizedImages->first(fn ($image) => ! empty($image['is_primary'])) ?? $normalizedImages->first();
        $mainImageUrl = is_array($mainImage) ? ($mainImage['url'] ?? null) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'slug' => $this->slug,
            'spu' => $this->spu,
            'price' => $price,
            'formatted_price' => 'Rp ' . number_format($price, 0, ',', '.'),
            'discounted_price' => $discountedPrice,
            'stock' => $stock,
            'is_in_stock' => $isInStock,
            'stock_label' => $stockLabel,
            'inventory' => $inventory,
            'variant_pricing' => $variantPricingItems,
            'specs' => $variants,
            'images' => $normalizedImages->all(),
            'photos' => $normalizedImages->all(),
            'main_image' => $mainImageUrl,
            'status' => $this->product_status,
        ];
    }
}
