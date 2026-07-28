<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashTransaction;
use App\Models\FeeType;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        // ledger_category يحدّد سطر المداخيل في التقرير المالي:
        // القسط الشهري → معاليم الأشهر، الميدعة والتجهيزات → بيع المنتجات،
        // ERP vie scolaire → معاليم التسجيل (معلوم سنوي)، والخدمات → مداخيل أخرى.
        // يمكن للمسؤول تعديل التصنيف من شاشة أنواع المعاليم دون تغيير الكود.
        $fees = [
            ['name_ar' => 'القسط الشهري',   'name_fr' => 'Frais mensuels',  'price' => 0,  'ledger_category' => CashTransaction::CATEGORY_MONTHLY_FEE],
            ['name_ar' => 'ميدعة',          'name_fr' => 'Inscription',     'price' => 30, 'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE],
            ['name_ar' => 'التجهيزات',       'name_fr' => 'Équipements',     'price' => 40, 'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE],
            ['name_ar' => 'ERP vie scolaire','name_fr' => 'ERP vie scolaire','price' => 20, 'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE],
            ['name_ar' => 'حضانة',           'name_fr' => 'Garderie',        'price' => 30, 'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME],
            ['name_ar' => 'نادي الروبوتك',   'name_fr' => 'Club Robotique',  'price' => 10, 'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME],
            ['name_ar' => 'حساب ذهني',       'name_fr' => 'Calcul Mental',   'price' => 10, 'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME],
        ];

        foreach ($fees as $fee) {
            FeeType::updateOrCreate(
                ['name_ar' => $fee['name_ar']],
                [
                    'name_fr'         => $fee['name_fr'],
                    'price'           => $fee['price'],
                    'ledger_category' => $fee['ledger_category'],
                    'is_active'       => true,
                ]
            );
        }
    }
}
