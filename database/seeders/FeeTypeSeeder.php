<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeeType;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $fees = [
            ['name_ar' => 'ميدعة',          'name_fr' => 'Inscription',     'price' => 30],
            ['name_ar' => 'التجهيزات',       'name_fr' => 'Équipements',     'price' => 40],
            ['name_ar' => 'ERP vie scolaire','name_fr' => 'ERP vie scolaire','price' => 20],
            ['name_ar' => 'حضانة',           'name_fr' => 'Garderie',        'price' => 30],
            ['name_ar' => 'نادي الروبوتك',   'name_fr' => 'Club Robotique',  'price' => 10],
            ['name_ar' => 'حساب ذهني',       'name_fr' => 'Calcul Mental',   'price' => 10],
        ];

        foreach ($fees as $fee) {
            FeeType::updateOrCreate(
                ['name_ar' => $fee['name_ar']],
                [
                    'name_fr'   => $fee['name_fr'],
                    'price'     => $fee['price'],
                    'is_active' => true,
                ]
            );
        }
    }
}
