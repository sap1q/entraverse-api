<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $products = [
            [
                'name' => 'Sony PlayStation 5 Pro',
                'brand' => 'Sony',
                'category' => 'Gaming Console',
                'description' => 'Next-generation gaming console with enhanced graphics performance and ultra-fast storage.',
                'spu' => 'SONY-PS5-PRO-001',
                'trade_in' => true,
                'inventory' => [
                    'price' => 12499000,
                    'total_stock' => 65,
                    'weight' => 4500,
                ],
                'photos' => [
                    [
                        'url' => '/storage/products/sony-playstation-5-pro/front.jpg',
                        'alt' => 'Sony PlayStation 5 Pro front view',
                        'is_primary' => true,
                    ],
                    [
                        'url' => '/storage/products/sony-playstation-5-pro/angled.jpg',
                        'alt' => 'Sony PlayStation 5 Pro angled view',
                        'is_primary' => false,
                    ],
                ],
                'variants' => [
                    [
                        'type' => 'edition',
                        'items' => [
                            [
                                'code' => 'disc',
                                'label' => 'Disc Edition',
                                'attributes' => [
                                    'storage' => '2TB',
                                    'optical_drive' => true,
                                    'resolution_up_to' => '8K',
                                ],
                            ],
                            [
                                'code' => 'digital',
                                'label' => 'Digital Edition',
                                'attributes' => [
                                    'storage' => '2TB',
                                    'optical_drive' => false,
                                    'resolution_up_to' => '8K',
                                ],
                            ],
                        ],
                    ],
                ],
                'variant_pricing' => [
                    [
                        'currency' => 'IDR',
                        'items' => [
                            [
                                'variant_code' => 'disc',
                                'price' => 12499000,
                                'compare_at_price' => 12999000,
                                'stock' => 25,
                            ],
                            [
                                'variant_code' => 'digital',
                                'price' => 11999000,
                                'compare_at_price' => 12999000,
                                'stock' => 40,
                            ],
                        ],
                    ],
                ],
                'mekari_status' => [
                    'sync_status' => 'pending',
                    'last_sync' => null,
                    'mekari_id' => null,
                ],
                'created_by' => null,
                'updated_by' => null,
                'product_status' => 'active',
            ],
            [
                'name' => 'Meta Quest 3',
                'brand' => 'Meta',
                'category' => 'VR Headset',
                'description' => 'Mixed reality headset with high-resolution displays and immersive spatial experiences.',
                'spu' => 'META-QUEST3-001',
                'trade_in' => true,
                'inventory' => [
                    'price' => 7500000,
                    'total_stock' => 95,
                    'weight' => 515,
                ],
                'photos' => [
                    [
                        'url' => '/storage/products/meta-quest-3/front.jpg',
                        'alt' => 'Meta Quest 3 front view',
                        'is_primary' => true,
                    ],
                    [
                        'url' => '/storage/products/meta-quest-3/side.jpg',
                        'alt' => 'Meta Quest 3 side view',
                        'is_primary' => false,
                    ],
                ],
                'variants' => [
                    [
                        'type' => 'storage',
                        'items' => [
                            [
                                'code' => '128gb',
                                'label' => '128GB',
                                'attributes' => [
                                    'storage' => '128GB',
                                    'color' => 'White',
                                    'refresh_rate' => '120Hz',
                                ],
                            ],
                            [
                                'code' => '512gb',
                                'label' => '512GB',
                                'attributes' => [
                                    'storage' => '512GB',
                                    'color' => 'White',
                                    'refresh_rate' => '120Hz',
                                ],
                            ],
                        ],
                    ],
                ],
                'variant_pricing' => [
                    [
                        'currency' => 'IDR',
                        'items' => [
                            [
                                'variant_code' => '128gb',
                                'price' => 7500000,
                                'compare_at_price' => 7999000,
                                'stock' => 60,
                            ],
                            [
                                'variant_code' => '512gb',
                                'price' => 9500000,
                                'compare_at_price' => 9999000,
                                'stock' => 35,
                            ],
                        ],
                    ],
                ],
                'mekari_status' => [
                    'sync_status' => 'pending',
                    'last_sync' => null,
                    'mekari_id' => null,
                ],
                'created_by' => null,
                'updated_by' => null,
                'product_status' => 'active',
            ],
            [
                'name' => 'Ray-Ban Meta Smart Glasses',
                'brand' => 'Ray-Ban x Meta',
                'category' => 'Smart Wearable',
                'description' => 'Smart glasses with built-in camera, audio, and AI assistant for everyday use.',
                'spu' => 'RB-META-SMART-001',
                'trade_in' => false,
                'inventory' => [
                    'price' => 5199000,
                    'total_stock' => 95,
                    'weight' => 49,
                ],
                'photos' => [
                    [
                        'url' => '/storage/products/ray-ban-meta-smart-glasses/front.jpg',
                        'alt' => 'Ray-Ban Meta Smart Glasses front view',
                        'is_primary' => true,
                    ],
                    [
                        'url' => '/storage/products/ray-ban-meta-smart-glasses/lifestyle.jpg',
                        'alt' => 'Ray-Ban Meta Smart Glasses lifestyle',
                        'is_primary' => false,
                    ],
                ],
                'variants' => [
                    [
                        'type' => 'frame_color',
                        'items' => [
                            [
                                'code' => 'matte-black',
                                'label' => 'Matte Black',
                                'attributes' => [
                                    'frame_color' => 'Matte Black',
                                    'lens' => 'Clear',
                                    'camera' => '12MP',
                                ],
                            ],
                            [
                                'code' => 'shiny-black',
                                'label' => 'Shiny Black',
                                'attributes' => [
                                    'frame_color' => 'Shiny Black',
                                    'lens' => 'Green',
                                    'camera' => '12MP',
                                ],
                            ],
                            [
                                'code' => 'caramel',
                                'label' => 'Caramel',
                                'attributes' => [
                                    'frame_color' => 'Caramel',
                                    'lens' => 'Brown',
                                    'camera' => '12MP',
                                ],
                            ],
                        ],
                    ],
                ],
                'variant_pricing' => [
                    [
                        'currency' => 'IDR',
                        'items' => [
                            [
                                'variant_code' => 'matte-black',
                                'price' => 5199000,
                                'compare_at_price' => 5399000,
                                'stock' => 45,
                            ],
                            [
                                'variant_code' => 'shiny-black',
                                'price' => 5199000,
                                'compare_at_price' => 5399000,
                                'stock' => 30,
                            ],
                            [
                                'variant_code' => 'caramel',
                                'price' => 5399000,
                                'compare_at_price' => 5699000,
                                'stock' => 20,
                            ],
                        ],
                    ],
                ],
                'mekari_status' => [
                    'sync_status' => 'pending',
                    'last_sync' => null,
                    'mekari_id' => null,
                ],
                'created_by' => null,
                'updated_by' => null,
                'product_status' => 'active',
            ],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['spu' => $product['spu']],
                $product
            );
        }
    }
}
