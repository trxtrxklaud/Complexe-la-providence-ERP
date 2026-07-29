<?php

namespace Database\Seeders;

use App\Models\Level;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * الهيكل الرسمي للمؤسسة (بيانات المدرسة نفسها): المستويات وأقسامها كما هي
 * معتمدة إدارياً في مركب العناية.
 *
 * ملاحظة عن «مغادرون»: لا يُنشأ قسم خاص بالتلاميذ المغادرين، لأن المغادرة عندنا
 * حالة تسجيل لا مكاناً دراسياً: enrollments.status = withdrawn. هكذا يبقى عدد
 * الأقسام مطابقاً للواقع، ويظلّ التلميذ المغادر مربوطاً بقسمه الأصلي في التقارير.
 *
 * التشغيل: php artisan db:seed --class=ProvidenceStructureSeeder
 */
class ProvidenceStructureSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['code' => 'PRE1', 'name' => 'روضة',        'order' => 1, 'sections' => ['أميمة', 'عفاف مح']],
            ['code' => 'PRE2', 'name' => 'تمهيدي',      'order' => 2, 'sections' => ['عفاف بو', 'حنان', 'نجاح']],
            ['code' => 'PRE3', 'name' => 'تحضيري',     'order' => 3, 'sections' => ['صفاء', 'أمل', 'عفاف']],
            ['code' => 'L1',   'name' => 'السنة الأولى',  'order' => 4, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
            ['code' => 'L2',   'name' => 'السنة الثانية', 'order' => 5, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
            ['code' => 'L3',   'name' => 'السنة الثالثة', 'order' => 6, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
            ['code' => 'L4',   'name' => 'السنة الرابعة', 'order' => 7, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
            ['code' => 'L5',   'name' => 'السنة الخامسة', 'order' => 8, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
            ['code' => 'L6',   'name' => 'السنة السادسة', 'order' => 9, 'sections' => ['أ', 'ب', 'ج', 'د', 'ه']],
        ];

        $createdLevels = 0;
        $createdSections = 0;
        $renamed = 0;

        foreach ($levels as $data) {
            $level = Level::firstOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'order' => $data['order']]
            );

            if ($level->wasRecentlyCreated) {
                $createdLevels++;
            } elseif ((int) $level->order !== $data['order']) {
                $level->update(['order' => $data['order']]);
            }

            // توحيد التسمية: بذرة سابقة كتبت «هـ» بالتطويل، والمعتمد رسمياً «ه».
            if (in_array('ه', $data['sections'], true)) {
                $legacy = Section::where('level_id', $level->id)->where('name', 'هـ')->first();
                $exists = Section::where('level_id', $level->id)->where('name', 'ه')->exists();

                if ($legacy && ! $exists) {
                    $legacy->update(['name' => 'ه']);
                    $renamed++;
                } elseif ($legacy && $exists) {
                    // لا نحذف شيئاً قد يحمل تسجيلات — نترك القرار للمستخدم من الواجهة.
                    $this->command?->warn("قسم مكرر (هـ و ه) في المستوى {$level->code} — راجعه من شاشة المستويات والأقسام.");
                }
            }

            foreach ($data['sections'] as $name) {
                $section = Section::firstOrCreate(
                    ['level_id' => $level->id, 'name' => $name],
                    ['code' => $level->code . '-' . $name, 'capacity' => 30]
                );

                if ($section->wasRecentlyCreated) {
                    $createdSections++;
                }
            }
        }

        $this->command?->info("✅ البنية: +{$createdLevels} مستوى، +{$createdSections} قسم، {$renamed} تصحيح تسمية.");
        $this->command?->info('المجموع الآن: ' . Level::count() . ' مستوى · ' . Section::count() . ' قسم.');
    }
}
