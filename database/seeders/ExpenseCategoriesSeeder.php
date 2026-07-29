<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * أصناف المصاريف الافتراضية كما تصرفها المؤسسة فعلاً في موسمها الدراسي.
 * التنفيذ: php artisan db:seed --class=ExpenseCategoriesSeeder
 */
class ExpenseCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'مواد تنظيف',
            'أدوات مكتبية',
            'صيانة وإصلاح',
            'كهرباء وماء',
            'اتصالات وإنترنت',
            'نقل وتنقّل',
            'تغدية',
            'معدات وتجهيزات',
            'خدمات خارجية',
            'متفرقات',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::firstOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );
        }
    }
}
