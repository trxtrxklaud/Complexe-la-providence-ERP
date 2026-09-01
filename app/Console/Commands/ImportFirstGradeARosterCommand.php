<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportFirstGradeARosterCommand extends Command
{
    protected $signature = 'app:import-first-grade-a {--force : تنفيذ الإدخال الفعلي في قاعدة البيانات}';

    protected $description = 'استيراد قائمة تلاميذ السنة الأولى أ ومطابقتها ومنع أي تكرار';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->warn(' استيراد ومطابقة قائمة تلاميذ السنة الأولى أ (46 تلميذاً)');
        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->info('الوضع: ' . ($force ? 'تنفيذ فعلي (--force)' : 'معاينة فقط (Dry-Run)'));
        $this->newLine();

        $activeYear = AcademicYear::where('is_active', true)->first();
        if (! $activeYear) {
            $this->error('لا توجد سنة دراسية نشطة!');
            return self::FAILURE;
        }

        $section = Section::where('level_id', 1)->where('name', 'LIKE', '%أ%')->first();
        if (! $section) {
            $this->error('لم يتم العثور على قسم السنة الأولى أ!');
            return self::FAILURE;
        }

        $this->line(" • السنة الدراسية النشطة: {$activeYear->name} (ID: {$activeYear->id})");
        $this->line(" • القسم المستهدف: المستوى {$section->level_id} — القسم {$section->name} (ID: {$section->id})");
        $this->newLine();

        $roster = [
            ['num' => 1, 'name' => 'ليان نصيبي', 'father' => 'منبير نصيبي', 'mother' => 'سناء نصيبي', 'f_phone' => '27003251', 'm_phone' => '29674044'],
            ['num' => 2, 'name' => 'محمد عمري', 'father' => 'معز عمري', 'mother' => 'سمية', 'f_phone' => '22434218', 'm_phone' => '20277215'],
            ['num' => 3, 'name' => 'محمد ياسين بوعزيزي', 'father' => 'سامي بوعزيزي', 'mother' => 'تت', 'f_phone' => '93739144', 'm_phone' => '55646347'],
            ['num' => 4, 'name' => 'أرسلان قدري', 'father' => 'عبد السلام قدري', 'mother' => 'حليمة قدري', 'f_phone' => '55047063', 'm_phone' => '96022142'],
            ['num' => 5, 'name' => 'جود ابراهمي', 'father' => 'محمد الصالح ابراهمي', 'mother' => 'تت', 'f_phone' => '98722268', 'm_phone' => '00000000'],
            ['num' => 6, 'name' => 'جوليا جلالي', 'father' => 'محمد بن محمد', 'mother' => 'تت', 'f_phone' => '54454648', 'm_phone' => '55132100'],
            ['num' => 7, 'name' => 'زكرياء نايلي', 'father' => 'حسين نايلي', 'mother' => 'تت', 'f_phone' => '97057581', 'm_phone' => '58963796'],
            ['num' => 8, 'name' => 'شيماء علوي', 'father' => 'صابر علوي', 'mother' => 'مريم صغير', 'f_phone' => '21609815', 'm_phone' => '29195193'],
            ['num' => 9, 'name' => 'محمد أمين تليجاني', 'father' => 'فيصل تليجاني', 'mother' => 'هدى تليجاني', 'f_phone' => '21517216', 'm_phone' => '58074182'],
            ['num' => 10, 'name' => 'مالك حامدي', 'father' => 'معز حامدي', 'mother' => 'ممممم', 'f_phone' => '55371589', 'm_phone' => '00000000'],
            ['num' => 11, 'name' => 'براء حجلاوي', 'father' => 'ماهر حجلاوي', 'mother' => 'ووووووووووو', 'f_phone' => '98244574', 'm_phone' => '93132584'],
            ['num' => 12, 'name' => 'تسنيم علوي', 'father' => 'رشيد علوي', 'mother' => 'أماني عمراوي', 'f_phone' => '99800351', 'm_phone' => '92854704'],
            ['num' => 13, 'name' => 'بدر اولاد بوعزيز', 'father' => 'جميل اولاد بوعزيز', 'mother' => 'حنان سليماني', 'f_phone' => '21007102', 'm_phone' => '00000000'],
            ['num' => 14, 'name' => 'جود براهمي', 'father' => 'محمد الصالح براهمي', 'mother' => 'أسماء ضفولي', 'f_phone' => '98722268', 'm_phone' => '00000000'],
            ['num' => 15, 'name' => 'نور اليقين بكاري', 'father' => 'توفيق بكاري', 'mother' => 'تت', 'f_phone' => '98606946', 'm_phone' => '96739045'],
            ['num' => 16, 'name' => 'يحيى قدري', 'father' => 'رابح قدري', 'mother' => 'سهام بوعزيزي', 'f_phone' => '90459822', 'm_phone' => '40505125'],
            ['num' => 17, 'name' => 'محمد يازيد احمدي', 'father' => 'ناعم احمدي', 'mother' => 'اسماء عيساوي', 'f_phone' => '53613447', 'm_phone' => '21489877'],
            ['num' => 18, 'name' => 'ليليا بكاري', 'father' => 'وسيم بكاري', 'mother' => 'شذى خبابي', 'f_phone' => '29417808', 'm_phone' => '29597015'],
            ['num' => 19, 'name' => 'ميرال الزايري', 'father' => 'محمد الطيب الزايري', 'mother' => 'حبيبة الدربالي', 'f_phone' => '97462654', 'm_phone' => '92734718'],
            ['num' => 20, 'name' => 'جود جمني', 'father' => 'معز جمني', 'mother' => 'ريم عبيدي', 'f_phone' => '25324504', 'm_phone' => '97703404'],
            ['num' => 21, 'name' => 'جاد برقوقي', 'father' => 'محمد صابر برقوقي', 'mother' => 'إيمان يوسفي', 'f_phone' => '50919173', 'm_phone' => '95001140'],
            ['num' => 22, 'name' => 'محمد رسيم غربي', 'father' => 'سالم غربي', 'mother' => 'زهور ابراهمي', 'f_phone' => '22378355', 'm_phone' => '29538469'],
            ['num' => 23, 'name' => 'ألاء حجلاوي', 'father' => 'هيشام حجلاوي', 'mother' => 'ايناس حجلاوي', 'f_phone' => '97340246', 'm_phone' => '52874680'],
            ['num' => 24, 'name' => 'محمد بيرم عبد المؤمن', 'father' => 'خالد', 'mother' => 'الصالحة', 'f_phone' => '56044531', 'm_phone' => '29429387'],
            ['num' => 25, 'name' => 'زكرياء صالح', 'father' => 'عباس صالح', 'mother' => 'تت', 'f_phone' => '20304565', 'm_phone' => '97432253'],
            ['num' => 26, 'name' => 'يزيد منافقي', 'father' => 'ماجد منافقي', 'mother' => 'تت', 'f_phone' => '25088639', 'm_phone' => '58491511'],
            ['num' => 27, 'name' => 'عزيز تليلي', 'father' => 'بلال تليلي', 'mother' => 'تت', 'f_phone' => '29939917', 'm_phone' => '50907183'],
            ['num' => 28, 'name' => 'ايوب جوادي', 'father' => 'محمد ياسين جوادي', 'mother' => 'الهام بلهادي', 'f_phone' => '29146820', 'm_phone' => '50751710'],
            ['num' => 29, 'name' => 'ريحان العيفي', 'father' => 'بلال العيفي', 'mother' => 'تت', 'f_phone' => '25000329', 'm_phone' => '56846824'],
            ['num' => 30, 'name' => 'محمد أدم عثموني', 'father' => 'صابر عثموني', 'mother' => 'تت', 'f_phone' => '23272006', 'm_phone' => '00000000'],
            ['num' => 31, 'name' => 'صوفيا عجمي', 'father' => 'ياسين عجمي', 'mother' => 'امال علوي', 'f_phone' => '93130111', 'm_phone' => '98926180'],
            ['num' => 32, 'name' => 'فارس عزعوزي', 'father' => 'عصام عزعوزي', 'mother' => 'هيفاء غربي', 'f_phone' => '29088402', 'm_phone' => '00000000'],
            ['num' => 33, 'name' => 'محمد الياس شابي', 'father' => 'محمد البشير شابي', 'mother' => 'تت', 'f_phone' => '97093393', 'm_phone' => '97771814'],
            ['num' => 34, 'name' => 'أرسلان بوساحة', 'father' => 'سهام عافي', 'mother' => 'سهام عافي', 'f_phone' => '28095107', 'm_phone' => '00000000'],
            ['num' => 35, 'name' => 'يوسف نصري', 'father' => 'فيصل نصري', 'mother' => 'بب', 'f_phone' => '98981829', 'm_phone' => '00000000'],
            ['num' => 36, 'name' => 'أسيل السعيدي', 'father' => 'وائل السعيدي', 'mother' => 'تت', 'f_phone' => '41229922', 'm_phone' => '97746514'],
            ['num' => 37, 'name' => 'لميس الجلاصي', 'father' => 'أشرف الجلاصي', 'mother' => 'تت', 'f_phone' => '44612665', 'm_phone' => '92634348'],
            ['num' => 38, 'name' => 'محمد أيهم علوي', 'father' => 'الصادق علوي', 'mother' => 'تت', 'f_phone' => '55725714', 'm_phone' => '52526219'],
            ['num' => 39, 'name' => 'رميساء بن بوقرة', 'father' => 'حنان زبنوبي', 'mother' => 'بب', 'f_phone' => '00000000', 'm_phone' => '24628978'],
            ['num' => 40, 'name' => 'محمد هادي صغروني', 'father' => 'عبد الحفيظ صغروني', 'mother' => 'صابرة حامدي', 'f_phone' => '97352171', 'm_phone' => '55452293'],
            ['num' => 41, 'name' => 'أحمد متهني', 'father' => 'علي متهني', 'mother' => 'أمل عامري', 'f_phone' => '55324030', 'm_phone' => '52446561'],
            ['num' => 42, 'name' => 'أروى بالهادي', 'father' => 'راغب بالهادي', 'mother' => 'منى سعيدي', 'f_phone' => '26374525', 'm_phone' => '98163770'],
            ['num' => 43, 'name' => 'تيم حمدوني', 'father' => 'شريف حمدوني', 'mother' => 'بسمة حمدوني', 'f_phone' => '22201334', 'm_phone' => '54809201'],
            ['num' => 44, 'name' => 'تيم الله تومي', 'father' => 'حسام تومي', 'mother' => 'خولة السعيدي', 'f_phone' => '50449558', 'm_phone' => '50451877'],
            ['num' => 45, 'name' => 'القاسم حاجي', 'father' => 'قيس حاجي', 'mother' => 'تت', 'f_phone' => '54208899', 'm_phone' => '54401288'],
            ['num' => 46, 'name' => 'ميسان بوزيدي', 'father' => 'حسام بن محمد ناجي', 'mother' => 'تت', 'f_phone' => '20883946', 'm_phone' => '23696352'],
        ];

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($roster, $activeYear, $section, $force, &$imported, &$skipped) {
            foreach ($roster as $item) {
                $cleanName = preg_replace('/\s+/', ' ', trim($item['name']));
                $parts = explode(' ', $cleanName, 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';

                // فحص عدم التكرار: هل التلميذ مسجل بالفعل في هذا القسم؟
                $existing = Student::whereHas('enrollments', function ($q) use ($activeYear, $section) {
                    $q->where('academic_year_id', $activeYear->id)
                      ->where('section_id', $section->id)
                      ->where('status', 'active')
                      ->whereNull('deleted_at');
                })->where('first_name', $firstName)
                  ->where('last_name', $lastName)
                  ->first();

                if ($existing) {
                    $skipped++;
                    $this->line(" • [موجود مسبقاً — تم تخطيه] #{$item['num']} {$cleanName} (تلميذ #{$existing->id})");
                    continue;
                }

                if (! $force) {
                    $imported++;
                    $this->info(" • [جاهز للإدخال] #{$item['num']} {$cleanName} (الأب: {$item['father']} | الأم: {$item['mother']})");
                    continue;
                }

                // توليد رمز تلميذ فريد
                do {
                    $code = 'PRV-' . date('Y') . '-' . Str::upper(Str::random(6));
                } while (Student::where('student_code', $code)->exists());

                $fatherName = trim($item['father']);
                $motherName = trim($item['mother']);
                if (in_array($motherName, ['تت', 'بب', 'ممممم', 'ووووووووووو'])) {
                    $motherName = '';
                }

                $fPhone = $item['f_phone'] !== '00000000' ? $item['f_phone'] : null;
                $mPhone = $item['m_phone'] !== '00000000' ? $item['m_phone'] : null;

                $student = Student::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'student_code' => $code,
                    'guardian_first_name' => $fatherName,
                    'guardian_phone' => $fPhone,
                    'mother_name' => $motherName ?: null,
                    'mother_phone' => $mPhone,
                    'notes' => 'استيراد المنصة القديمة — الأولى أ — 2026-09-01',
                    'status' => 'active',
                ]);

                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $activeYear->id,
                    'level_id' => $section->level_id,
                    'section_id' => $section->id,
                    'enrollment_date' => '2026-09-01',
                    'status' => 'active',
                    'notes' => 'استيراد المنصة القديمة — الأولى أ — 2026-09-01',
                ]);

                $imported++;
                $this->info(" • [تم الإدخال بنجاح] #{$item['num']} {$cleanName} -> تلميذ #{$student->id} (رمز: {$code})");
            }

            if (! $force) {
                $this->newLine();
                $this->warn('معاينة فقط (Dry-Run). لم يتم تعديل أي بيانات. للإدخال الفعلي استخدم: --force');
            }
        });

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(" الإجمالي: " . count($roster) . " | تم الإدخال: {$imported} | مسجل مسبقاً (تم تخطيه): {$skipped}");
        $this->info('═══════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }
}
