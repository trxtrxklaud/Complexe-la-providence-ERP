<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\CollectionService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * اختبارات حالة ميزة نصف الشهر في ظل واقع الـ Schema الحالي:
 * الحالة: BLOCKED_MISSING_PERSISTENT_TUITION_IDENTIFIER
 * تثبت الاختبارات أن first_half و remaining محظوران برسالة واضحة،
 * بينما يستمر وضع full في العمل الطبيعي بدون أي تأثر.
 */
class HalfMonthCollectionTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $collectionService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectionService = app(CollectionService::class);
        $this->paymentService = app(PaymentService::class);
    }

    private function createPreschoolEnrollment(string $levelCode = 'PRE1', float $tuitionPrice = 100.0): array
    {
        $year = $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name' => match ($levelCode) {
                'PRE1' => 'روضة',
                'PRE2' => 'تمهيدي',
                'PRE3' => 'تحضيري',
                default => 'ابتدائي',
            },
            'code' => $levelCode,
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name' => 'فوج أ',
            'code' => 'SEC_' . $suffix,
            'capacity' => 25,
        ]);

        $student = Student::create([
            'student_code' => 'STU_' . $suffix,
            'first_name' => 'يوسف',
            'last_name' => 'المهدوي',
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

        // FeeType الحقيقي دون أي عمود code اصطناعي
        $tuitionType = FeeType::create([
            'name_ar' => 'القسط الشهري',
            'price' => $tuitionPrice,
            'ledger_category' => 'monthly_fee',
            'is_active' => true,
        ]);

        $feeCategory = FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'Frais Scolaires', 'is_recurring' => true]
        );

        $feePlan = FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'fee_category_id' => $feeCategory->id,
            'name' => 'القسط الشهري — ' . $level->name,
            'amount' => $tuitionPrice,
            'frequency' => 'monthly',
        ]);

        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType', 'feePlan', 'user');
    }

    public function test_fee_type_has_no_synthetic_code_attribute(): void
    {
        $data = $this->createPreschoolEnrollment();
        $type = $data['tuitionType']->fresh();

        $this->assertNull($type->code);
        $this->assertFalse(array_key_exists('code', $type->getAttributes()));
    }

    public function test_first_half_collection_is_blocked_with_clear_message(): void
    {
        $data = $this->createPreschoolEnrollment('PRE1', 100.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('القبض الجزئي للشهر غير متاح حالياً.');

        $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'first_half',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 50.0],
            ],
        ], $data['user']->id);
    }

    public function test_remaining_collection_is_blocked_with_clear_message(): void
    {
        $data = $this->createPreschoolEnrollment('PRE1', 100.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('القبض الجزئي للشهر غير متاح حالياً.');

        $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-20',
            'method' => 'cash',
            'collection_mode' => 'remaining',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 50.0],
            ],
        ], $data['user']->id);
    }

    public function test_first_half_preview_is_blocked_with_clear_message(): void
    {
        $data = $this->createPreschoolEnrollment('PRE1', 100.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('القبض الجزئي للشهر غير متاح حالياً.');

        $this->collectionService->preview(
            $data['enrollment']->id,
            ['2025-09'],
            $data['tuitionType']->id,
            'first_half'
        );
    }

    public function test_remaining_preview_is_blocked_with_clear_message(): void
    {
        $data = $this->createPreschoolEnrollment('PRE1', 100.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('القبض الجزئي للشهر غير متاح حالياً.');

        $this->collectionService->preview(
            $data['enrollment']->id,
            ['2025-09'],
            $data['tuitionType']->id,
            'remaining'
        );
    }

    public function test_full_collection_mode_continues_to_operate_normally_for_primary_level(): void
    {
        $data = $this->createPreschoolEnrollment('L1', 100.0);

        $preview = $this->collectionService->preview(
            $data['enrollment']->id,
            ['2025-09'],
            $data['tuitionType']->id,
            'full'
        );

        $this->assertSame(100.0, $preview['remaining_amount']);
        $this->assertSame('full', $preview['collection_mode']);

        $receipt = $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'full',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ], $data['user']->id);

        $this->assertNotNull($receipt['payment_id']);
        $this->assertSame(100.0, (float) $receipt['total']);
        $this->assertContains('2025-09', $this->collectionService->getPaidMonths($data['enrollment']->id));
    }

    public function test_full_collection_mode_allows_preschool_for_september_with_suggested_rate(): void
    {
        $data = $this->createPreschoolEnrollment('PRE1', 100.0);

        $preview = $this->collectionService->preview(
            $data['enrollment']->id,
            ['2025-09'],
            $data['tuitionType']->id,
            'full'
        );

        $this->assertSame(50.0, $preview['remaining_amount']);
    }
}
