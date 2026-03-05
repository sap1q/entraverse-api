<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => $this->faker->unique()->words(2, true),
            'icon' => null,
            'fees' => [
                'marketplace' => ['components' => []],
                'shopee' => ['components' => []],
                'entraverse' => ['components' => []],
                'tokopedia_tiktok' => ['components' => []],
            ],
            'program_garansi' => json_encode([], JSON_THROW_ON_ERROR),
            'min_margin' => $this->faker->randomFloat(2, 0, 25),
        ];
    }

    public function withIcon(): static
    {
        return $this->state(fn (): array => [
            'icon' => '/storage/categories/icons/test-icon.svg',
        ]);
    }

    public function withFees(): static
    {
        return $this->state(fn (): array => [
            'fees' => [
                'marketplace' => [
                    'components' => [
                        ['label' => 'Biaya Marketplace', 'value' => 9.5, 'valueType' => 'percent'],
                    ],
                ],
                'shopee' => [
                    'components' => [
                        ['label' => 'Biaya Shopee', 'value' => 5.0, 'valueType' => 'percent'],
                    ],
                ],
                'entraverse' => [
                    'components' => [
                        ['label' => 'Biaya Entraverse', 'value' => 2.5, 'valueType' => 'percent'],
                    ],
                ],
                'tokopedia_tiktok' => [
                    'components' => [],
                ],
            ],
        ]);
    }
}
