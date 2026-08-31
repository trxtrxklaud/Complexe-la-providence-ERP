<?php

use App\Models\CashTransaction;
use App\Models\FeeType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            [
                'name_ar'         => 'معلوم الترسيم',
                'name_fr'         => 'Frais d\'inscription',
                'price'           => 70.00,
                'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
                'is_active'       => true,
            ],
            [
                'name_ar'         => 'ميدعة',
                'name_fr'         => 'Tablier / Blouse',
                'price'           => 30.00,
                'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
                'is_active'       => true,
            ],
            [
                'name_ar'         => 'ERP vie scolaire',
                'name_fr'         => 'Vie Scolaire',
                'price'           => 20.00,
                'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME,
                'is_active'       => true,
            ],
            [
                'name_ar'         => 'رزمة أوراق',
                'name_fr'         => 'Ram de papier',
                'price'           => 15.00,
                'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
                'is_active'       => true,
            ],
        ];

        foreach ($defaults as $item) {
            $existing = FeeType::where('name_ar', $item['name_ar'])
                ->orWhere('name_fr', $item['name_fr'])
                ->first();

            if (! $existing) {
                FeeType::create($item);
            }
        }
    }

    public function down(): void
    {
        // Keep fee types to avoid losing references
    }
};
