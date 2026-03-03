<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Illuminate\Support\Str;

final class VariantGenerator
{
    /**
     * @param array<int,array{name:string,values:array<int,string>}> $attributes
     * @return array<int,array<string,string>>
     */
    public function generateCombinations(array $attributes): array
    {
        $normalized = array_values(array_filter(array_map(function (array $attribute): array {
            $name = trim((string) ($attribute['name'] ?? ''));
            $values = array_values(array_filter(array_map(
                fn ($value): string => trim((string) $value),
                $attribute['values'] ?? []
            )));

            return ['name' => $name, 'values' => $values];
        }, $attributes), fn (array $attribute): bool => $attribute['name'] !== '' && $attribute['values'] !== []));

        if ($normalized === []) {
            return [[]];
        }

        $combinations = [[]];
        foreach ($normalized as $attribute) {
            $next = [];
            foreach ($combinations as $current) {
                foreach ($attribute['values'] as $value) {
                    $next[] = [...$current, $attribute['name'] => $value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param array<string,float|int> $variantFactors
     */
    public function calculateVariantPrice(float $basePrice, array $variantFactors): float
    {
        $fixedAdd = (float) ($variantFactors['fixed'] ?? 0);
        $percentAdd = (float) ($variantFactors['percent'] ?? 0);

        $price = max(0, $basePrice) + max(0, $fixedAdd);
        return $price + ($price * (max(0, $percentAdd) / 100));
    }

    /**
     * @param array<string,string> $variantValues
     */
    public function generateSku(string $productCode, array $variantValues, int $sequence = 1): string
    {
        $base = Str::upper(trim($productCode));
        if ($base === '') {
            $base = 'PRD';
        }

        $parts = array_map(
            fn (string $value): string => Str::upper(substr(preg_replace('/[^A-Za-z0-9]/', '', $value) ?: 'X', 0, 4)),
            array_values($variantValues)
        );

        $suffix = str_pad((string) max(1, $sequence), 3, '0', STR_PAD_LEFT);
        return implode('-', array_filter([$base, ...$parts, $suffix]));
    }
}
