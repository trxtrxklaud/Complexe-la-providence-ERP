<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\OpeningBalance;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\FamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ديون التلاميذ غير المعاد تسجيلهم في السنة الحالية تظهر في قائمة العائلات.
 *
 * listFamilies: التلميذ المتسجل حالياً يُحسب عبر شبكة الأشهر، والمغادر (له
 * تسجيل سابق فقط) تُحتسب رسومه القديمة المستحقة (مثل: دَين قديم — إدخال جماعي)
 * من تسجيله الأخير بدل أن تُهمل ويعرض «مستوفى».
 */
class FamilyListLeaverDebtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => false,
        ]);

        AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
            'is_current' => true,
        ]);

        $level = Level::create(['name' => 'المستوى الأول', 'code' => 'L1']);
        $section = Section::create(['level_id' => $level->id, 'name' => 'أ', 'code' => 'L1-A', 'capacity' => 30]);

        // خطة شهرية للسنة الحالية فقط — الحساب الشهري للتلميذ المسجل.
        $feeCat = FeeCategory::create(['code' => 'TUITION', 'name' => 'معلوم التمدرس', 'is_recurring' => true]);
        FeePlan::create([
            'academic_year_id' => AcademicYear::where('is_active', true)->first()->id,
            'fee_category_id' => $feeCat->id,
            'level_id' => $level->id,
            'name' => 'معلوم التمدرس الأساسي',
            'amount' => 190.00,
            'frequency' => 'monthly',
        ]);

        FeeType::create([
            'name_ar' => 'معلوم التمدرس',
            'name_fr' => 'Frais Scolarite',
            'code' => 'TUITION',
            'price' => 190.00,
            'is_recurring' => true,
        ]);

        // تلميذ مُعاد تسجيله هذا العام.
        $this->reEnrolled = Student::create([
            'student_code' => 'PRV-LEAV-001',
            'first_name' => 'ياسين',
            'last_name' => 'بن صالح',
            'gender' => 'boy',
            'birth_date' => '2018-01-01',
            'guardian_phone' => '99887766',
        ]);
        Enrollment::create([
            'student_id' => $this->reEnrolled->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $this->reEnrolled->id,
            'academic_year_id' => 2,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);

        // تلميذ مغادر: تسجيل سابق فقط بلا تسجيل للسنة الحالية.
        $this->leaver = Student::create([
            'student_code' => 'PRV-LEAV-002',
            'first_name' => 'مريم',
            'last_name' => 'بن صالح',
            'gender' => 'girl',
            'birth_date' => '2019-05-05',
            'guardian_phone' => '99887766',
        ]);
        $leaverEnrollment = Enrollment::create([
            'student_id' => $this->leaver->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        // دين قديم مستحق على تسجيل المغادر (مثل «دَين قديم — إدخال جماعي»).
        StudentFee::create([
            'enrollment_id' => $leaverEnrollment->id,
            'fee_type_id' => null,
            'amount_due' => 500.00,
            'amount_paid' => 0.00,
            'status' => 'pending',
            'due_date' => '2026-09-01',
            'description' => 'دَين قديم: ديون قديمة — إدخال جماعي (2025-2026)',
        ]);
    }

    public function test_leaver_old_debt_is_counted_in_family_list(): void
    {
        $result = app(FamilyService::class)->listFamilies(null, 100, 1);

        $family = collect($result['data'])->firstWhere('phone', '99887766');

        $this->assertNotNull($family, 'العائلة يجب أن تظهر في القائمة');
        $this->assertSame(2, $family['students_count']);
        $this->assertSame(2, count($family['students']));

        // التلميذ المسجل: شهر سبتمبر 190 (غير مسدد) + المغادر: دين 500.
        $this->assertEqualsWithDelta(690.0, (float) $family['family_remaining_debt'], 0.001);
    }

    public function test_leaver_debt_student_is_listed_in_family(): void
    {
        $result = app(FamilyService::class)->listFamilies(null, 100, 1);

        $family = collect($result['data'])->firstWhere('phone', '99887766');

        $leaverStudent = collect($family['students'])->firstWhere('id', $this->leaver->id);
        $this->assertNotNull($leaverStudent, 'التلميذ المغادر يجب أن يظهر بين الأبناء');
        $this->assertEqualsWithDelta(500.0, (float) $leaverStudent['remaining_debt'], 0.001);

        $reEnrolledStudent = collect($family['students'])->firstWhere('id', $this->reEnrolled->id);
        $this->assertNotNull($reEnrolledStudent);
        $this->assertEqualsWithDelta(190.0, (float) $reEnrolledStudent['remaining_debt'], 0.001);
    }

    public function test_debt_free_leaver_does_not_appear_in_family_list(): void
    {
        $level = Level::first();
        $section = Section::first();

        // تلميذ مغادر ضمن نفس العائلة ولكن دون أي دين متبقٍ
        $debtFreeSibling = Student::create([
            'student_code' => 'PRV-LEAV-003',
            'first_name' => 'أحمد',
            'last_name' => 'بن صالح',
            'gender' => 'boy',
            'birth_date' => '2017-03-03',
            'guardian_phone' => '99887766',
        ]);
        $enr = Enrollment::create([
            'student_id' => $debtFreeSibling->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
        // رسم مدفوع بالكامل
        StudentFee::create([
            'enrollment_id' => $enr->id,
            'fee_type_id' => null,
            'amount_due' => 200.00,
            'amount_paid' => 200.00,
            'status' => 'paid',
            'due_date' => '2025-10-01',
            'description' => 'معلوم مدفوع بالكامل',
        ]);

        // عائلة أخرى بالكامل مغادرة وبلا ديون
        $isolatedDebtFree = Student::create([
            'student_code' => 'PRV-LEAV-004',
            'first_name' => 'خالد',
            'last_name' => 'المغادر',
            'gender' => 'boy',
            'birth_date' => '2016-04-04',
            'guardian_phone' => '11223344',
        ]);
        Enrollment::create([
            'student_id' => $isolatedDebtFree->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $result = app(FamilyService::class)->listFamilies(null, 100, 1);

        // العائلة الأولى: التلميذ الخالي من الدين لا يظهر بين الأبناء
        $family = collect($result['data'])->firstWhere('phone', '99887766');
        $this->assertNotNull($family);
        $this->assertSame(2, $family['students_count'], 'يجب أن يقتصر الأبناء على التلميذ المسجل والمغادر الذي يحمل ديناً فقط');
        $this->assertNull(collect($family['students'])->firstWhere('id', $debtFreeSibling->id));

        // العائلة المستقلة التي لا تحمل ديوناً لا تظهر مطلقاً في القائمة
        $this->assertNull(collect($result['data'])->firstWhere('phone', '11223344'));
    }

    public function test_leaver_with_opening_balance_debt_appears(): void
    {
        $level = Level::first();
        $section = Section::first();
        $activeYear = AcademicYear::where('is_active', true)->first();

        $leaver = Student::create([
            'student_code' => 'PRV-OB-LEAV',
            'first_name' => 'سارة',
            'last_name' => 'المنستيري',
            'gender' => 'girl',
            'birth_date' => '2018-05-05',
            'guardian_phone' => '55443322',
        ]);
        $oldEnr = Enrollment::create([
            'student_id' => $leaver->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
        $oldFee = StudentFee::create([
            'enrollment_id' => $oldEnr->id,
            'fee_type_id' => null,
            'amount_due' => 350.00,
            'amount_paid' => 0.00,
            'status' => 'pending',
            'due_date' => '2025-11-01',
            'description' => 'معلوم دراسي سابق',
        ]);

        OpeningBalance::create([
            'student_id' => $leaver->id,
            'source_enrollment_id' => $oldEnr->id,
            'source_student_fee_id' => $oldFee->id,
            'academic_year_id' => $activeYear->id,
            'amount' => 350.00,
            'status' => OpeningBalance::STATUS_PENDING,
        ]);

        $result = app(FamilyService::class)->listFamilies(null, 100, 1);
        $family = collect($result['data'])->firstWhere('phone', '55443322');

        $this->assertNotNull($family, 'عائلة المغادر ذي الرصيد الافتتاحي يجب أن تظهر');
        $studentItem = collect($family['students'])->firstWhere('id', $leaver->id);
        $this->assertNotNull($studentItem);
        $this->assertEqualsWithDelta(350.0, (float) $studentItem['remaining_debt'], 0.001);
        $this->assertEqualsWithDelta(350.0, (float) $family['family_remaining_debt'], 0.001);
    }

    public function test_leaver_with_manual_debt_appears(): void
    {
        $level = Level::first();
        $section = Section::first();
        $activeYear = AcademicYear::where('is_active', true)->first();

        $leaver = Student::create([
            'student_code' => 'PRV-MD-LEAV',
            'first_name' => 'طارق',
            'last_name' => 'المهدوي',
            'gender' => 'boy',
            'birth_date' => '2017-07-07',
            'guardian_phone' => '44332211',
        ]);
        Enrollment::create([
            'student_id' => $leaver->id,
            'academic_year_id' => 1,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        ManualStudentDebt::create([
            'student_id' => $leaver->id,
            'academic_year_id' => $activeYear->id,
            'original_year_label' => '2024-2025',
            'debt_type' => 'tuition',
            'description' => 'دين متخلد يدوي مرحل',
            'original_amount' => 420.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        $result = app(FamilyService::class)->listFamilies(null, 100, 1);
        $family = collect($result['data'])->firstWhere('phone', '44332211');

        $this->assertNotNull($family, 'عائلة المغادر ذي الدين اليدوي يجب أن تظهر');
        $studentItem = collect($family['students'])->firstWhere('id', $leaver->id);
        $this->assertNotNull($studentItem);
        $this->assertEqualsWithDelta(420.0, (float) $studentItem['remaining_debt'], 0.001);
        $this->assertEqualsWithDelta(420.0, (float) $family['family_remaining_debt'], 0.001);
    }
}
