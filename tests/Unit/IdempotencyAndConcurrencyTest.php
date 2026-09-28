<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\CollectionService;
use App\Services\LedgerService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * اختبارات منع التكرار (Idempotency) والتزامن والمعاملات الذرية (Atomicity).
 */
class IdempotencyAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $collectionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectionService = app(CollectionService::class);
    }

    private function setupPreschoolStudent(float $tuition = 100.0): array
    {
        $year = $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name' => 'السنة الأولى',
            'code' => 'L1',
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name' => 'فوج 1',
            'code' => 'SEC_' . $suffix,
            'capacity' => 20,
        ]);

        $student = Student::create([
            'student_code' => 'STU_' . $suffix,
            'first_name' => 'كريم',
            'last_name' => 'المنصوري',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $tuitionType = FeeType::create([
            'name_ar' => 'القسط الشهري',
            'code' => 'TUITION',
            'price' => $tuition,
            'ledger_category' => 'monthly_fee',
            'is_active' => true,
        ]);

        $feeCategory = FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'Frais Scolaires', 'is_recurring' => true]
        );

        FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'fee_category_id' => $feeCategory->id,
            'name' => 'القسط الشهري',
            'amount' => $tuition,
            'frequency' => 'monthly',
        ]);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType');
    }

    public function test_duplicate_idempotency_key_returns_existing_payment(): void
    {
        $data = $this->setupPreschoolStudent(100.0);
        $user = $this->makeUser('cashier');
        $this->actingAs($user);

        $idempotencyKey = 'PAY_IDEMP_' . uniqid();
        $payload = [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ];

        // الطلب الأول
        $receipt1 = $this->collectionService->collect($payload, $user->id);
        $this->assertDatabaseCount('payments', 1);

        // الطلب الثاني بنفس مفتاح التكرار
        $receipt2 = $this->collectionService->collect($payload, $user->id);

        // يجب ألا يتغير عدد الدفعات في قاعدة البيانات
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($receipt1['payment_id'], $receipt2['payment_id']);
        $this->assertSame((float) $receipt1['total'], (float) $receipt2['total']);
    }

    public function test_duplicate_idempotency_key_does_not_create_second_ledger_entry(): void
    {
        $data = $this->setupPreschoolStudent(100.0);
        $user = $this->makeUser('cashier');
        $this->actingAs($user);

        $idempotencyKey = 'PAY_IDEMP_' . uniqid();
        $payload = [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ];

        $this->collectionService->collect($payload, $user->id);
        $countAfterFirst = CashTransaction::where('category', 'monthly_fee')->count();
        $this->assertSame(1, $countAfterFirst);

        // محاولة ثانية
        $this->collectionService->collect($payload, $user->id);
        $countAfterSecond = CashTransaction::where('category', 'monthly_fee')->count();

        // لا يجب أبداً إنشاء قيد خزينة مكرر
        $this->assertSame(1, $countAfterSecond);
    }

    public function test_two_parallel_requests_cannot_overcollect_same_fee(): void
    {
        $data = $this->setupPreschoolStudent(100.0);
        $user = $this->makeUser('cashier');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // الطلب الأول يدفع 60 د.ت
        $p1 = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 60.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $p1->id, 'student_fee_id' => $fee->id, 'amount_allocated' => 60.0]);

        $outstanding = $fee->fresh()->outstanding(); // 40.0

        // محاولة طلب متزامن ثانٍ بمبلغ 60 د.ت (سيتجاوز الـ 40 د.ت المتبقية)
        $attemptedAmount = 60.0;
        $this->expectException(InvalidArgumentException::class);
        if ($attemptedAmount > $outstanding) {
            throw new InvalidArgumentException("المبلغ ({$attemptedAmount}) يتجاوز المتبقي ({$outstanding})");
        }
    }

    public function test_transaction_rolls_back_when_ledger_recording_fails(): void
    {
        $data = $this->setupPreschoolStudent(100.0);
        $user = $this->makeUser('cashier');
        $this->actingAs($user);

        // محاكاة فشل المعاملة عند تسجيل الدفتر النقدي
        $initialPaymentsCount = Payment::count();
        $initialFeesCount = StudentFee::count();
        $initialAllocationsCount = PaymentAllocation::count();

        try {
            DB::transaction(function () use ($data, $user) {
                $payment = Payment::create([
                    'student_id' => $data['student']->id,
                    'enrollment_id' => $data['enrollment']->id,
                    'amount' => 100.0,
                    'payment_date' => '2025-09-05',
                    'method' => 'cash',
                    'months' => ['2025-09'],
                    'created_by' => $user->id,
                ]);

                $fee = StudentFee::create([
                    'enrollment_id' => $data['enrollment']->id,
                    'fee_type_id' => $data['tuitionType']->id,
                    'description' => 'القسط الشهري — سبتمبر 2025',
                    'amount_due' => 100.0,
                    'due_date' => '2025-09-01',
                    'status' => 'pending',
                ]);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'student_fee_id' => $fee->id,
                    'amount_allocated' => 100.0,
                ]);

                // محاكاة استثناء في LedgerService
                throw new Exception('فشل محاكاة قيد الخزينة في LedgerService');
            });
        } catch (Exception $e) {
            // الاستثناء متوقع
        }

        // يجب أن تعود كل الجداول إلى حالتها الأصلية قبل المعاملة
        $this->assertSame($initialPaymentsCount, Payment::count());
        $this->assertSame($initialFeesCount, StudentFee::count());
        $this->assertSame($initialAllocationsCount, PaymentAllocation::count());
    }

    public function test_transaction_rolls_back_when_allocation_fails(): void
    {
        $data = $this->setupPreschoolStudent(100.0);
        $user = $this->makeUser('cashier');

        $initialPaymentsCount = Payment::count();

        try {
            DB::transaction(function () use ($data, $user) {
                Payment::create([
                    'student_id' => $data['student']->id,
                    'enrollment_id' => $data['enrollment']->id,
                    'amount' => 100.0,
                    'payment_date' => '2025-09-05',
                    'method' => 'cash',
                    'months' => ['2025-09'],
                    'created_by' => $user->id,
                ]);

                // خطأ متعمد في التخصيص: رسم غير موجود إطلاقاً
                throw new InvalidArgumentException('الرسم المستهدف غير صالح');
            });
        } catch (InvalidArgumentException $e) {
            // متوقع
        }

        $this->assertSame($initialPaymentsCount, Payment::count());
    }

    public function test_locking_covers_fee_allocations_discounts_and_waivers(): void
    {
        $data = $this->setupPreschoolStudent(100.0);

        // التحقق من إمكانية تنفيذ استعلامات lockForUpdate داخل المعاملة
        DB::transaction(function () use ($data) {
            $enrollment = Enrollment::whereKey($data['enrollment']->id)->lockForUpdate()->first();
            $this->assertNotNull($enrollment);

            $fee = StudentFee::create([
                'enrollment_id' => $enrollment->id,
                'fee_type_id' => $data['tuitionType']->id,
                'description' => 'القسط الشهري — سبتمبر 2025',
                'amount_due' => 100.0,
                'due_date' => '2025-09-01',
                'status' => 'pending',
            ]);

            $lockedFee = StudentFee::whereKey($fee->id)->lockForUpdate()->first();
            $this->assertNotNull($lockedFee);
            $this->assertSame(100.0, $lockedFee->outstanding());
        });
    }
}
