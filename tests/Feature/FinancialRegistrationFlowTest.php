<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\RegistrationArrearsService;
use App\Services\RegistrationPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $registrar;
    protected AcademicYear $activeYear;
    protected Level $level;
    protected Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->level = Level::create(['name' => 'السنة الأولى', 'code' => 'G1', 'order' => 1]);
        $this->section = Section::create(['name' => 'أ', 'code' => '1A', 'level_id' => $this->level->id]);

        $role = \App\Models\Role::firstOrCreate(
            ['name' => 'registrar'],
            ['display_name' => 'مسؤول التسجيل', 'description' => 'إدارة التسجيل']
        );

        $perm = Permission::firstOrCreate(
            ['name' => 'manage_students'],
            ['display_name' => 'إدارة التلاميذ', 'group' => 'Students']
        );
        $role->permissions()->attach($perm->id);

        $this->registrar = User::create([
            'username' => 'registrar_' . Str::random(4),
            'first_name' => 'مسؤول',
            'last_name' => 'التسجيل',
            'email' => 'registrar@providence.test',
            'password' => bcrypt('Password123!'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function createTestStudent(): Student
    {
        return Student::create([
            'first_name' => 'سامي',
            'last_name' => 'العياري',
            'dob' => '2018-03-12',
            'gender' => 'male',
            'student_code' => 'PRV-' . Str::random(6),
            'status' => 'active',
        ]);
    }

    private function createTestEnrollment(Student $student): Enrollment
    {
        $pastYear = AcademicYear::firstOrCreate(
            ['name' => '2025-2026'],
            ['start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => false]
        );

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $pastYear->id,
            'level_id' => $this->level->id,
            'section_id' => $this->section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
    }

    private function createActiveEnrollment(Student $student): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->activeYear->id,
            'level_id' => $this->level->id,
            'section_id' => $this->section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);
    }

    /**
     * 1. إسناد تلميذ إلى قسم دون إنشاء رسوم أو قبض أو حركة خزينة
     */
    public function test_assigning_student_to_section_creates_no_fees_payments_or_cash(): void
    {
        Sanctum::actingAs($this->registrar);

        $enrollmentService = app(EnrollmentService::class);
        $enrollment = $enrollmentService->enrollStudent([
            'first_name' => 'محمد',
            'last_name' => 'الطرابلسي',
            'dob' => '2019-05-10',
            'gender' => 'male',
            'guardian_first_name' => 'علي',
            'guardian_last_name' => 'الطرابلسي',
            'guardian_phone' => '98123456',
            'address' => 'سيدي بوزيد',
            'section_id' => $this->section->id,
        ]);

        $this->assertInstanceOf(Enrollment::class, $enrollment);
        $this->assertSame(1, Enrollment::count());
        $this->assertSame(0, StudentFee::count(), 'إسناد القسم لا ينشئ أي رسوم تلقائية');
        $this->assertSame(0, Payment::count(), 'إسناد القسم لا ينشئ أي سند قبض');
        $this->assertSame(0, CashTransaction::count(), 'إسناد القسم لا ينشئ أي حركة خزينة');
    }

    /**
     * 2. دفع معلوم الترسيم وإنشاء Payment وAllocation وCashTransaction واحدة
     */
    public function test_paying_registration_fee_creates_atomic_payment_allocation_and_cash(): void
    {
        Sanctum::actingAs($this->registrar);

        $student = $this->createTestStudent();
        $enrollment = $this->createTestEnrollment($student);

        $feeType = FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);

        $requestId = (string) Str::uuid();

        $response = $this->postJson("/api/students/{$student->id}/reenroll", [
            'client_request_id' => $requestId,
            'section_id' => $this->section->id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
            'fee_items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 70, 'description' => 'معلوم الترسيم'],
            ],
        ]);

        $response->assertCreated();

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame(1, CashTransaction::count());

        $payment = Payment::firstOrFail();
        $cash = CashTransaction::firstOrFail();

        $this->assertSame($requestId, $payment->idempotency_key);
        $this->assertSame(CashTransaction::CATEGORY_REGISTRATION_FEE, $cash->category);
        $this->assertEqualsWithDelta(70.0, (float) $cash->amount, 0.001);
        $this->assertSame(CashTransaction::DIRECTION_IN, $cash->direction);
    }

    /**
     * 3. إعادة إرسال نفس UUID وعدم تكرار السند أو الخزينة
     */
    public function test_resubmitting_same_uuid_is_strictly_idempotent(): void
    {
        Sanctum::actingAs($this->registrar);

        $student = $this->createTestStudent();
        $enrollment = $this->createTestEnrollment($student);

        $feeType = FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);

        $requestId = 'req-fixed-uuid-1234';

        $payload = [
            'client_request_id' => $requestId,
            'section_id' => $this->section->id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
            'fee_items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 70, 'description' => 'معلوم الترسيم'],
            ],
        ];

        $this->postJson("/api/students/{$student->id}/reenroll", $payload)->assertCreated();
        $this->postJson("/api/students/{$student->id}/reenroll", $payload)->assertCreated();

        $this->assertSame(1, Payment::count(), 'تكرار نفس المفتاح لا ينشئ دفعاً جديداً');
        $this->assertSame(1, CashTransaction::count(), 'تكرار نفس المفتاح لا يضاعف الخزينة');
        $this->assertSame(1, PaymentAllocation::count());
    }

    /**
     * 4. دفع UUID جديد في اليوم نفسه وبنفس المبلغ والسماح به
     */
    public function test_new_uuid_allows_subsequent_payment_on_same_day(): void
    {
        Sanctum::actingAs($this->registrar);

        $student = $this->createTestStudent();
        $enrollment = $this->createTestEnrollment($student);

        $ftReg = FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);
        $ftEquip = FeeType::create([
            'name_ar' => 'معلوم التجهيزات',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
            'is_active' => true,
        ]);

        // الدفعة الأولى: معلوم الترسيم
        $this->postJson("/api/students/{$student->id}/reenroll", [
            'client_request_id' => (string) Str::uuid(),
            'section_id' => $this->section->id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
            'fee_items' => [
                ['fee_type_id' => $ftReg->id, 'amount' => 70, 'description' => 'معلوم الترسيم'],
            ],
        ])->assertCreated();

        // الدفعة الثانية: معلوم التجهيزات بنفس المبلغ في نفس اليوم
        $this->postJson("/api/students/{$student->id}/registration-payment", [
            'client_request_id' => (string) Str::uuid(),
            'section_id' => $this->section->id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
            'fee_items' => [
                ['fee_type_id' => $ftEquip->id, 'amount' => 70, 'description' => 'معلوم التجهيزات'],
            ],
        ])->assertCreated();

        $this->assertSame(2, Payment::count());
        $this->assertSame(2, CashTransaction::count());
    }

    /**
     * 5. عدم إنشاء متخلد قبل نهاية المهلة
     */
    public function test_arrears_service_does_not_run_before_deadline(): void
    {
        $student = $this->createTestStudent();
        $this->createActiveEnrollment($student);

        $arrearsService = app(RegistrationArrearsService::class);
        $result = $arrearsService->generateArrearsAfterDeadline('2026-09-15');

        $this->assertSame('pending_deadline', $result['status']);
        $this->assertSame(0, $result['arrears_created']);
        $this->assertSame(0, StudentFee::count(), 'لا متخلدات قبل 30 سبتمبر');
    }

    /**
     * 6. إنشاء متخلد بعد نهاية المهلة وإعادة التشغيل آمنة ضد التكرار
     */
    public function test_arrears_service_creates_arrears_after_deadline_and_is_idempotent(): void
    {
        $feeType = FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);

        $student = $this->createTestStudent();
        $this->createActiveEnrollment($student);

        $arrearsService = app(RegistrationArrearsService::class);

        // التشغيل الأول بعد المهلة (1 أكتوبر)
        $run1 = $arrearsService->generateArrearsAfterDeadline('2026-10-01');
        $this->assertSame('success', $run1['status']);
        $this->assertSame(1, $run1['arrears_created']);
        $this->assertSame(1, StudentFee::count());

        $fee = StudentFee::firstOrFail();
        $this->assertSame('pending', $fee->status);
        $this->assertEqualsWithDelta(70.0, (float) $fee->amount_due, 0.001);
        $this->assertSame(0, Payment::count(), 'لا سندات دفع للمتخلد');
        $this->assertSame(0, CashTransaction::count(), 'لا حركات خزينة للمتخلد');

        // إعادة التشغيل الثانية: التحقق من منع التكرار
        $run2 = $arrearsService->generateArrearsAfterDeadline('2026-10-02');
        $this->assertSame('success', $run2['status']);
        $this->assertSame(0, $run2['arrears_created'], 'لا تكرار للرسم');
        $this->assertSame(1, $run2['already_existing_arrears']);
        $this->assertSame(1, StudentFee::count(), 'يبقى رسماً واحداً دون تكرار');
    }

    /**
     * 7. الديون القديمة manual_student_debts لا تتأثر
     */
    public function test_manual_student_debts_are_preserved(): void
    {
        $student = $this->createTestStudent();
        $debt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->activeYear->id,
            'original_year_label' => '2025-2026',
            'description' => 'متخلد دراسي قديم',
            'original_amount' => 350.000,
            'notes' => 'دين متخلد حقيقي من سنة سابقة',
            'status' => 'pending',
        ]);

        $this->assertSame(1, ManualStudentDebt::count());
        $this->assertEqualsWithDelta(350.000, (float) $debt->fresh()->original_amount, 0.001);
    }

    /**
     * 8. الدفع بدون client_request_id يُرفض استثنائياً
     */
    public function test_payment_without_client_request_id_is_rejected(): void
    {
        Sanctum::actingAs($this->registrar);

        $student = $this->createTestStudent();
        $this->createTestEnrollment($student);

        $response = $this->postJson("/api/students/{$student->id}/reenroll", [
            'section_id' => $this->section->id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_request_id']);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CashTransaction::count());
    }
}
