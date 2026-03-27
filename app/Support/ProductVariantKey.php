<?php

declare(strict_types=1);

namespace App\Support;

final class ProductVariantKey
{
    /**
     * @param  array<string, mixed>  $variant
     */
    public static function resolve(array $variant, int $index = 0): string
    {
        $candidates = [
            $variant['id'] ?? null,
            $variant['uuid'] ?? null,
            $variant['variant_id'] ?? null,
            $variant['sku_seller'] ?? null,
            $variant['sku'] ?? null,
            $variant['variant_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'variant-' . max(1, $index + 1);
    }
}
