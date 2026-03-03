<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Virtual Reality',
                'icon' => 'Box', // Nama icon Lucide
                'fee_tokopedia_tiktok' => 'Layanan: 5.5% + Ongkir: 1.5%',
                'fee_shopee' => 'Biaya Admin: 6.0% (Star+)',
                'fee_entraverse' => 'Internal: 0% + Gateway: 2%',
                'program_garansi' => 'Wajib Serial Number (SN)',
                'min_margin' => 15.00,
            ],
            [
                'name' => 'Aksesoris Elektronik',
                'icon' => 'Cable',
                'fee_tokopedia_tiktok' => 'Layanan: 4.0% + Cashback: 2%',
                'fee_shopee' => 'Biaya Admin: 4.5%',
                'fee_entraverse' => 'Internal: 0%',
                'program_garansi' => 'Non-Garansi / 7 Hari Toko',
                'min_margin' => 25.00,
            ],
            [
                'name' => 'Audio & Speaker',
                'icon' => 'Headphones',
                'fee_tokopedia_tiktok' => 'Layanan: 5.0%',
                'fee_shopee' => 'Biaya Admin: 5.5%',
                'fee_entraverse' => 'Internal: 0%',
                'program_garansi' => 'Garansi Resmi 1 Tahun',
                'min_margin' => 20.00,
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
