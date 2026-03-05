<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $basePrice = $this->faker->numberBetween(100_000, 10_000_000);
        $purchasePrice = (int) round($basePrice * 0.75);
        $stock = $this->faker->numberBetween(1, 100);

        return [
            'id' => (string) Str::uuid(),
            'name' => $this->faker->words(3, true),
            'category' => 'General',
            'category_id' => Category::factory(),
            'brand' => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'stock' => $stock,
            'trade_in' => false,
            'inventory' => [
                'price' => $basePrice,
                'cost' => $purchasePrice,
                'total_stock' => $stock,
                'weight' => $this->faker->numberBetween(100, 5_000),
            ],
            'photos' => [],
            'variants' => [],
            'variant_pricing' => [
                [
                    'name' => 'Default',
                    'entraverse_price' => $basePrice,
                    'purchase_price_idr' => $purchasePrice,
                    'stock' => $stock,
                ],
            ],
            'mekari_status' => [
                'sync_status' => 'pending',
                'last_sync' => null,
                'mekari_id' => null,
            ],
            'jurnal_id' => null,
            'jurnal_metadata' => null,
            'last_synced_at' => null,
            'spu' => strtoupper($this->faker->bothify('SPU-####')),
            'product_status' => $this->faker->randomElement(['active', 'pending_approval', 'inactive']),
        ];
    }

    public function withJurnal(): static
    {
        return $this->state(fn (): array => [
            'jurnal_id' => 'jurnal-'.Str::uuid(),
            'last_synced_at' => now(),
            'mekari_status' => [
                'sync_status' => 'success',
                'last_sync' => now()->toISOString(),
                'mekari_id' => 'jurnal-'.Str::uuid(),
            ],
        ]);
    }

    public function withVariants(int $count = 2): static
    {
        return $this->state(function () use ($count): array {
            $variants = [];
            $variantPricing = [];
            $totalStock = 0;

            for ($i = 1; $i <= $count; $i++) {
                $stock = $this->faker->numberBetween(1, 40);
                $sell = $this->faker->numberBetween(100_000, 3_000_000);
                $buy = (int) round($sell * 0.7);
                $totalStock += $stock;

                $variants[] = [
                    'name' => "Variant {$i}",
                    'option' => "V{$i}",
                ];

                $variantPricing[] = [
                    'variant' => "Variant {$i}",
                    'entraverse_price' => $sell,
                    'purchase_price_idr' => $buy,
                    'stock' => $stock,
                ];
            }

            return [
                'stock' => $totalStock,
                'variants' => $variants,
                'variant_pricing' => $variantPricing,
                'inventory' => [
                    'price' => $variantPricing[0]['entraverse_price'] ?? 0,
                    'cost' => $variantPricing[0]['purchase_price_idr'] ?? 0,
                    'total_stock' => $totalStock,
                    'weight' => $this->faker->numberBetween(100, 5_000),
                ],
            ];
        });
    }
}
