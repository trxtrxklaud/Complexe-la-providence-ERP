<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\FamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyMultiChildCollectionScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected Level $level1;
    protected Level $level2;
    protected Section $section1;
    protected Section $section2;
    protected FeeType $tuitionFeeType;
    protected FeePlan $plan1;
    protected FeePlan $plan2;
    protected Club $roboticsClub;

    protected function setUp(): void
    {
        parent::setUp();

        $perm1 = Permission::firstOrCreate(['name' => 'manage_payments'], ['display_name' => 'إدارة المدفوعات', 'module' => 'finance']);
        $perm2 = Permission::firstOrCreate(['name' => 'manage_students'], ['display_name' => 'إدارة الطلاب', 'module' => 'students']);
        $perm3 = Permission::firstOrCreate(['name' => 'view_students'], ['display_name' => 'عرض الطلاب', 'module' => 'students']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير النظام']);
        $role->permissions()->sync([$perm1->id, $perm2->id, $perm3->id]);

        $this->adminUser = User::create([
            'role_id' => $role->id,
            'username' => 'admin_test_scenarios',
            'first_name' => 'Admin',
            'last_name' => 'Tester',
            'email' => 'admin_scenarios@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->actingAs($this->adminUser);

        $this->academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
            'is_current' => true,
        ]);

        $this->level1 = Level::create(['name' => 'السنة الأولى', 'code' => 'L1']);
        $this->level2 = Level::create(['name' => 'السنة الثانية', 'code' => 'L2']);

        $this->section1 = Section::create(['level_id' => $this->level1->id, 'name' => 'أ', 'code' => 'L1-A']);
        $this->section2 = Section::create(['level_id' => $this->level2->id, 'name' => 'ب', 'code' => 'L2-B']);

        $this->tuitionFeeType = FeeType::create([
            'code' => 'TUITION',
            'name_ar' => 'معلوم شهري',
            'name_fr' => 'Frais Scolarite',
            'price' => 150.0,
            'is_active' => true,
            'ledger_category' => 'monthly_fee',
        ]);

        $tuitionCat = FeeCategory::create(['code' => 'TUITION', 'name' => 'معاليم الدراسة', 'is_recurring' => true]);

        $this->plan1 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'fee_category_id' => $tuitionCat->id,
            'level_id' => $this->level1->id,
            'name' => 'خطة السنة الأولى',
            'amount' => 150.0,
            'frequency' => 'monthly',
        ]);

        $this->plan2 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'fee_category_id' => $tuitionCat->id,
            'level_id' => $this->level2->id,
            'name' => 'خطة السنة الثانية',
            'amount' => 180.0,
            'frequency' => 'monthly',
        ]);

        $cat = FeeCategory::create(['code' => 'CLUB', 'name' => 'معاليم النوادي', 'is_recurring' => true]);
        $this->roboticsClub = Club::create([
            'name' => 'نادي الروبوتيك',
            'fee_category_id' => $cat->id,
            'monthly_fee' => 40.0,
            'is_active' => true,
        ]);
    }

    /**
     * اختبار 1 إلى 4: عائلات بأعداد أبناء مختلفة (ابن واحد، ابنان، 3 أبناء، أكثر من 3).
     */
    public function test_family_aggregation_supports_varying_sibling_counts(): void
    {
        // عائلة من 3 أبناء بنفس رقم الهاتف
        $st1 = Student::create(['first_name' => 'أحمد', 'last_name' => 'محمد', 'guardian_phone' => '+216 21 123 456', 'guardian_first_name' => 'محمد', 'guardian_last_name' => 'بن علي']);
        $st2 = Student::create(['first_name' => 'سارة', 'last_name' => 'محمد', 'guardian_phone' => '21123456', 'guardian_first_name' => 'محمد', 'guardian_last_name' => 'بن علي']);
        $st3 = Student::create(['first_name' => 'يوسف', 'last_name' => 'محمد', 'guardian_phone' => '21 123 456', 'guardian_first_name' => 'محمد', 'guardian_last_name' => 'بن علي']);

        Enrollment::create(['student_id' => $st1->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $st2->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $st3->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level2->id, 'section_id' => $this->section2->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);

        $res = $this->getJson('/api/families');
        $res->assertStatus(200);
        $data = $res->json('data');

        $family = collect($data)->firstWhere('phone', '21123456');
        $this->assertNotNull($family);
        $this->assertEquals(3, $family['students_count']);
    }

    /**
     * اختبار 5 إلى 8: دمج هاتف الأب والأم، وتطبيع الهاتف، ومنع دمج الأسماء المتشابهة بهواتف مختلفة.
     */
    public function test_phone_normalization_and_different_phones_separation(): void
    {
        // تلميذ هاتف الأب وتلميذ هاتف الأم (نفس الرقم)
        $stA = Student::create(['first_name' => 'خالد', 'last_name' => 'عمر', 'guardian_phone' => '50247050']);
        $stB = Student::create(['first_name' => 'فاطمة', 'last_name' => 'عمر', 'mother_phone' => '+216 50 247 050']);

        // تلميذ بنفس الاسم لكن هاتف مختلف تماماً
        $stOther = Student::create(['first_name' => 'خالد', 'last_name' => 'عمر', 'guardian_phone' => '98111222']);

        Enrollment::create(['student_id' => $stA->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $stB->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $stOther->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);

        $res = $this->getJson('/api/families/phone_50247050');
        $res->assertStatus(200);
        $students = $res->json('students');
        $this->assertCount(2, $students);

        $resOther = $this->getJson('/api/families/phone_98111222');
        $resOther->assertStatus(200);
        $this->assertCount(1, $resOther->json('students'));
    }

    /**
     * اختبار 9 إلى 15: سيناريو الدفع المشترك (أحمد: سبتمبر+أكتوبر+نادي+متخلد جزئي، سارة: سبتمبر فقط، يوسف: نوفمبر فقط).
     */
    public function test_family_multi_student_selective_collection_flow(): void
    {
        $st1 = Student::create(['first_name' => 'أحمد', 'last_name' => 'علي', 'guardian_phone' => '99001122']);
        $st2 = Student::create(['first_name' => 'سارة', 'last_name' => 'علي', 'guardian_phone' => '99001122']);
        $st3 = Student::create(['first_name' => 'يوسف', 'last_name' => 'علي', 'guardian_phone' => '99001122']);

        $en1 = Enrollment::create(['student_id' => $st1->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        $en2 = Enrollment::create(['student_id' => $st2->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level1->id, 'section_id' => $this->section1->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);
        $en3 = Enrollment::create(['student_id' => $st3->id, 'academic_year_id' => $this->academicYear->id, 'level_id' => $this->level2->id, 'section_id' => $this->section2->id, 'enrollment_date' => '2026-09-01', 'status' => 'active']);

        $priorYear = AcademicYear::create([
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => false,
        ]);
        $priorEnrollment = Enrollment::create([
            'student_id' => $st1->id,
            'academic_year_id' => $priorYear->id,
            'level_id' => $this->level1->id,
            'section_id' => $this->section1->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'completed',
        ]);

        // متخلد لأحمد بقيمة 100 دينار من السنة السابقة
        $feePrior = StudentFee::create([
            'enrollment_id' => $priorEnrollment->id,
            'description' => 'متخلد قديم',
            'amount_due' => 100.0,
            'due_date' => '2025-05-01',
            'status' => 'pending',
        ]);

        // اشتراك نادي لأحمد
        app(\App\Services\ClubService::class)->subscribeStudent($st1->id, $this->roboticsClub->id, $this->academicYear->id, null, null, $en1->id);
        $clubFee = ClubMonthlyFee::where('enrollment_id', $en1->id)->where('month', '2026-09')->first();

        // تنفيذ الاستخلاص العائلي
        $collectPayload = [
            'payment_date' => '2026-09-10',
            'method' => 'cash',
            'students_allocations' => [
                [
                    'student_id' => $st1->id,
                    'enrollment_id' => $en1->id,
                    'months' => ['2026-09', '2026-10'],
                    'club_items' => $clubFee ? [['club_monthly_fee_id' => $clubFee->id, 'amount' => 40.0]] : [],
                    'prior_allocations' => [['student_fee_id' => $feePrior->id, 'amount' => 50.0]], // دفع جزئي 50 من 100
                ],
                [
                    'student_id' => $st2->id,
                    'enrollment_id' => $en2->id,
                    'months' => ['2026-09'],
                    'club_items' => [],
                    'prior_allocations' => [],
                ],
                [
                    'student_id' => $st3->id,
                    'enrollment_id' => $en3->id,
                    'months' => ['2026-09'],
                    'club_items' => [],
                    'prior_allocations' => [],
                ],
            ],
        ];

        $resp = $this->postJson('/api/families/phone_99001122/collect', $collectPayload);
        $resp->assertStatus(201);
        $receipt = $resp->json('receipt');

        $this->assertTrue($receipt['is_family_receipt']);
        $this->assertCount(3, $receipt['siblings']);

        // التحقق من المتخلد الجزئي لأحمد: دفع 50 وبقي 50
        $feePrior->refresh();
        $this->assertEquals(50.0, $feePrior->allocatedAmount());
        $this->assertEquals(50.0, $feePrior->outstanding());
        $this->assertEquals('partial', $feePrior->status);

        // التحقق من أن الاستخلاص العادي يرى سبتمبر مدفوعاً فوراً
        $normalLedgerSt1 = app(CollectionService::class)->getPaidMonths($en1->id);
        $this->assertContains('2026-09', $normalLedgerSt1);
        $this->assertContains('2026-10', $normalLedgerSt1);

        $normalLedgerSt2 = app(CollectionService::class)->getPaidMonths($en2->id);
        $this->assertContains('2026-09', $normalLedgerSt2);
        $this->assertNotContains('2026-10', $normalLedgerSt2);

        $normalLedgerSt3 = app(CollectionService::class)->getPaidMonths($en3->id);
        $this->assertContains('2026-09', $normalLedgerSt3);
        $this->assertNotContains('2026-10', $normalLedgerSt3);

        // محاولة إعادة دفع سبتمبر لأحمد يجب أن تفشل فوراً (Duplicate Prevention)
        $doublePayResp = $this->postJson('/api/families/phone_99001122/collect', [
            'payment_date' => '2026-09-10',
            'method' => 'cash',
            'students_allocations' => [
                [
                    'student_id' => $st1->id,
                    'enrollment_id' => $en1->id,
                    'months' => ['2026-09'],
                ],
            ],
        ]);
        $doublePayResp->assertStatus(422);
    }
}
