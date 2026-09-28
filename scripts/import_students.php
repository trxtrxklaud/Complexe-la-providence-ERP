<?php

/*
 * ============================================================
 *  import_students.php — استيراد التلاميذ من المنصة القديمة
 *  إلى المنصة الجديدة (laprovidencemfondationmnrh.cloud)
 *
 *  - يقرأ merged_import.json (158 تلميذًا مستخرجًا من القديمة)
 *  - ينشئ Student + Guardian + Enrollment في معاملة واحدة لكل تلميذ
 *  - الحماية من التكرار:
 *      1) فحص الاسم الكامل + اسم الأب
 *      2) فحص (first_name, last_name, dob) إن وُجد dob
 *  - idempotent: إعادة التشغيل تتخطى الموجودين
 *  - لا يلمس أي جداول مالية (payments/fees) — تسجيل بلا معلوم ترسيم
 * ============================================================
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Student;
use App\Models\Guardian;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Level;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$jsonFile = __DIR__.'/merged_import.json';
if (!file_exists($jsonFile)) {
    echo "❌ الملف غير موجود: {$jsonFile}".PHP_EOL;
    exit(1);
}

$students = json_decode(file_get_contents($jsonFile), true);
if (!is_array($students)) {
    echo '❌ فشل قراءة JSON'.PHP_EOL;
    exit(1);
}

echo '=== استيراد التلاميذ من المنصة القديمة ==='.PHP_EOL;
echo 'عدد التلاميذ في الملف: '.count($students).PHP_EOL.PHP_EOL;

// السنة النشطة
$year = AcademicYear::where('is_active', true)->firstOrFail();
echo "السنة النشطة: {$year->name}".PHP_EOL;

// خريطة الأقسام (level, section) => id
$sectionMap = [];
foreach (Section::with('level')->get() as $s) {
    $sectionMap[trim($s->level->name).'|'.trim($s->name)] = $s;
}

$created = 0;
$skipped = 0;
$errors = 0;

// فهرس الموجودين محليًا (اسم كامل)
$existing = [];
foreach (Student::all() as $s) {
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s->first_name.' '.$s->last_name)));
    $existing[$key] = $s;
}

foreach ($students as $data) {
    $name = trim($data['name']);
    $levelName = trim($data['level']);
    // توحيد تسمية القسم الأخير: القديمة تستعمل "ه" والجديدة "هـ"
    $secName = trim($data['section']);
    if ($secName === 'ه') $secName = 'هـ';

    // 1) القسم موجود؟ (تجربة الاسم كما هو ثم بتوحيد ه->هـ)
    $section = $sectionMap[$levelName.'|'.$secName] ?? null;
    if (!$section && $secName === 'هـ') {
        $section = $sectionMap[$levelName.'|ه'] ?? null;
    }
    if (!$section) {
        echo "⚠️  قسم غير موجود: {$levelName}/{$secName} — تخطي: {$name}".PHP_EOL;
        $errors++;
        continue;
    }

    // 2) التلميذ موجود مسبقًا؟ (بالاسم الكامل)
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    if (isset($existing[$key])) {
        echo "⏭️  موجود مسبقًا: {$name}".PHP_EOL;
        $skipped++;
        continue;
    }

    // 3) فحص إضافي بالاسم + تاريخ الميلاد إن وُجد
    if (!empty($data['dob'])) {
        $dup = Student::where('first_name', $data['first_name'])
            ->where('last_name', $data['last_name'])
            ->where('dob', $data['dob'])
            ->exists();
        if ($dup) {
            echo "⏭️  موجود (بنفس الاسم وتاريخ الميلاد): {$name}".PHP_EOL;
            $skipped++;
            continue;
        }
    }

    try {
        DB::transaction(function () use ($data, $section, $year, &$existing, $name) {
            // كود طالب فريد
            $attempts = 0;
            do {
                if (++$attempts > 10) throw new \RuntimeException('فشل توليد كود طالب');
                $code = 'PRV-'.now()->year.'-'.strtoupper(Str::random(6));
            } while (Student::where('student_code', $code)->exists());

            $student = Student::create([
                'student_code' => $code,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'dob' => $data['dob'] ?: null,
                'gender' => $data['gender'],
                'status' => 'active',
                'guardian_first_name' => $data['guardian_first'] ?: null,
                'guardian_last_name' => $data['guardian_last'] ?: null,
                'guardian_phone' => $data['guardian_phone'] ?: null,
                'mother_name' => $data['mother_name'] ?: null,
                'mother_phone' => $data['mother_phone'] ?: null,
                'enrollment_date' => now()->toDateString(),
            ]);

            // سجل الولي في جدول guardians — الحقول phone/last_name/address إجبارية في المخطط
            // فيُنشأ السجل فقط عند توفر اسم وهاتف، وإلا تبقى بيانات الولي على صف التلميذ فقط
            if (!empty($data['guardian_first']) && !empty($data['guardian_phone'])) {
                $guardian = Guardian::create([
                    'first_name' => $data['guardian_first'],
                    'last_name' => $data['guardian_last'] ?: 'غير محدد',
                    'phone' => $data['guardian_phone'],
                    'address' => '-',
                    'mother_phone' => $data['mother_phone'] ?: null,
                ]);
                $student->guardians()->attach($guardian->id, [
                    'relationship' => 'primary',
                    'is_primary_contact' => true,
                ]);
            }

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'level_id' => $section->level_id,
                'section_id' => $section->id,
                'enrollment_date' => now()->toDateString(),
                'status' => 'active',
            ]);

            $existing[mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)))] = $student;
        });

        echo "✅ {$name} → {$levelName}/{$secName}".PHP_EOL;
        $created++;
    } catch (\Throwable $e) {
        echo "❌ فشل: {$name} — ".$e->getMessage().PHP_EOL;
        $errors++;
    }
}

echo PHP_EOL.'=== النتيجة ==='.PHP_EOL;
echo "✅ أُنشئوا: {$created}".PHP_EOL;
echo "⏭️  تخطي (موجودون): {$skipped}".PHP_EOL;
echo "❌ أخطاء: {$errors}".PHP_EOL;
echo 'إجمالي التلاميذ الآن: '.Student::count().PHP_EOL;
echo 'إجمالي التسجيلات: '.Enrollment::count().PHP_EOL;
