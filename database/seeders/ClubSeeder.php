<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\FeeCategory;
use Illuminate\Database\Seeder;

class ClubSeeder extends Seeder
{
    public function run(): void
    {
        $category = FeeCategory::firstOrCreate(
            ['code' => 'CLUB'],
            ['name' => 'معاليم النوادي', 'is_recurring' => true]
        );

        $clubs = [
            ['name' => 'الحساب الذهني', 'description' => 'نادي الحساب الذهني والتنمية الفكرية', 'monthly_fee' => 40.00, 'is_active' => true],
            ['name' => 'الروبوتيك', 'description' => 'نادي البرمجة والربوتات المدرسية', 'monthly_fee' => 50.00, 'is_active' => true],
        ];

        foreach ($clubs as $clubData) {
            Club::firstOrCreate(
                ['name' => $clubData['name']],
                array_merge($clubData, ['fee_category_id' => $category->id])
            );
        }
    }
}
