<?php

/*
 * ============================================================
 *  transfer_sections.php — نقل/مزامنة أسماء الأقسام
 *  المصدر: complexelaprovidence.com (المنصة القديمة)
 *  الهدف:  laprovidencemfondationmnrh.cloud (المنصة الجديدة)
 *
 *  ماذا يفعل:
 *  1) يأخذ نسخة احتياطية من جدولي sections و levels (ملف JSON)
 *  2) يعيد تسمية 6 أقسام موجودة (روضة/تمهيدي/تحضيري)
 *     من أسماء المعلمات إلى الأسماء الرمزية للمنصة القديمة
 *  3) يحدّث حقل code ليعكس الاسم الجديد
 *  4) idempotent: إعادة تشغيله لا تكسر شيئًا
 *
 *  التطابق المعتمد (2026/2027):
 *    روضة    / عفاف محمو  -> عفاف مح   (14 تلميذًا في القديمة)
 *    تمهيدي  / عفاف بو    -> المرج     (2)
 *    تمهيدي  / حنان       -> الكتاكيت  (9)
 *    تحضيري  / صفاء       -> العصافير  (14)
 *    تحضيري  / أمل        -> الفراشات  (9)
 *    تحضيري  / عفاف       -> السنافر   (20)
 *  تبقى كما هي (لا مقابل لها في القديمة):
 *    روضة / أميمة م، تمهيدي / نجاح
 *  لا يُنقل (بناءً على قرار المالك):
 *    مغادرون 22/23، 24/25، 2025/2026
 *    الخامسة و، تمهيدي ..............
 * ============================================================
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Level;
use App\Models\Section;
use Illuminate\Support\Facades\DB;

$map = [
    // 'اسم المستوى' => [ 'الاسم القديم للقسم' => 'الاسم الجديد' ]
    'روضة' => [
        'عفاف محمو' => 'عفاف مح',
    ],
    'تمهيدي' => [
        'عفاف بو' => 'المرج',
        'حنان' => 'الكتاكيت',
    ],
    'تحضيري' => [
        'صفاء' => 'العصافير',
        'أمل' => 'الفراشات',
        'عفاف' => 'السنافر',
    ],
];

echo '=== نقل أسماء الأقسام من المنصة القديمة ===' . PHP_EOL;

// ---------- 1) نسخة احتياطية ----------
$backup = [
    'sections' => Section::with('level:id,name')->get()->map(fn ($s) => [
        'id' => $s->id,
        'level' => $s->level->name,
        'name' => $s->name,
        'code' => $s->code,
        'capacity' => $s->capacity,
    ])->all(),
    'levels' => Level::all()->map(fn ($l) => [
        'id' => $l->id, 'name' => $l->name, 'code' => $l->code, 'order' => $l->order,
    ])->all(),
];
$backupFile = __DIR__.'/sections_backup_'.date('Ymd_His').'.json';
file_put_contents($backupFile, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo '✅ نسخة احتياطية: '.$backupFile.PHP_EOL;

// ---------- 2) إعادة التسمية ----------
$renamed = 0;
$skipped = 0;

foreach ($map as $levelName => $pairs) {
    $level = Level::where('name', $levelName)->first();
    if (! $level) {
        echo '⚠️  المستوى غير موجود: '.$levelName.PHP_EOL;
        continue;
    }

    foreach ($pairs as $oldName => $newName) {
        $section = Section::where('level_id', $level->id)
            ->where('name', $oldName)
            ->first();

        if (! $section) {
            // ربما نُفّذ النقل سابقًا — تحقق أن الاسم الجديد موجود مسبقًا
            $already = Section::where('level_id', $level->id)
                ->where('name', $newName)
                ->exists();
            echo $already
                ? "⏭️  منجز مسبقًا: {$levelName} / {$newName}"
                : "⚠️  القسم غير موجود: {$levelName} / {$oldName}";
            echo PHP_EOL;
            $skipped++;
            continue;
        }

        DB::transaction(function () use ($section, $level, $newName) {
            $oldCode = $section->code;
            $newCode = $level->code.'-'.$newName;

            // تجنب تضارب الـ code الفريد عند وجود قسمين يتبادلان الأسماء
            if ($oldCode !== $newCode && Section::where('code', $newCode)->where('id', '!=', $section->id)->exists()) {
                Section::where('code', $newCode)->where('id', '!=', $section->id)
                    ->update(['code' => 'TMP-'.uniqid()]);
            }

            $section->update([
                'name' => $newName,
                'code' => $newCode,
            ]);
        });

        echo "✅ {$levelName} / {$oldName} → {$newName}".PHP_EOL;
        $renamed++;
    }
}

// ---------- 3) تقرير نهائي ----------
echo PHP_EOL.'=== النتيجة ===' . PHP_EOL;
echo "✅ أعيدت تسمية: {$renamed}".PHP_EOL;
echo "⏭️  تخطي: {$skipped}".PHP_EOL.PHP_EOL;

foreach (Section::with('level')->orderBy('level_id')->orderBy('name')->get() as $s) {
    echo '  '.$s->level->name.' / '.$s->name.' (code='.$s->code.')'.PHP_EOL;
}
echo PHP_EOL.'إجمالي الأقسام: '.Section::count().PHP_EOL;
