<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات الـ API لميزة استخلاص نصف الشهر تحت حالة:
 * BLOCKED_MISSING_PERSISTENT_TUITION_IDENTIFIER
 * تثبت الاختبارات رفض الـ API لأوضاع first_half و remaining برسالة عمل صريحة،
 * واستمرار عمل المعاينة والاستخلاص العادي (full) بنجاح كامل.
 */
class CollectionHalfMonthApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupPreschoolApiEnvironment(float $tuitionAmount = 100.0, string $levelCode = 'PRE1'): array
    {
        $year = $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name' => str_starts_with($levelCode, 'PRE') ? 'روضة' : 'سنة أولى',
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
            'first_name' => 'أنس',
            'last_name' => 'الطرابلسي',
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
            'price' => $tuitionAmount,
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
            'name' => 'القسط الشهري — ' . $level->name,
            'amount' => $tuitionAmount,
            'frequency' => 'monthly',
        ]);

        $cashier = $this->makeUser('admin');
        $cashier->update(['is_active' => true]);
        $perm = \App\Models\Permission::firstOrCreate(
            ['name' => 'manage_payments'],
            ['display_name' => 'إدارة الدفعات', 'group' => 'Payments']
        );
        $cashier->role->permissions()->syncWithoutDetaching($perm->id);
        $cashier = $cashier->fresh(['role.permissions']);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType', 'cashier');
    }

    public function test_api_preview_blocks_first_half_mode_with_clear_message(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0);
        Sanctum::actingAs($data['cashier']);

        $response = $this->getJson('/api/payments/collect/preview?' . http_build_query([
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'fee_type_id' => $data['tuitionType']->id,
            'collection_mode' => 'first_half',
        ]));

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'القبض الجزئي للشهر غير متاح حالياً.']);
    }

    public function test_api_preview_blocks_remaining_mode_with_clear_message(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0);
        Sanctum::actingAs($data['cashier']);

        $response = $this->getJson('/api/payments/collect/preview?' . http_build_query([
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'fee_type_id' => $data['tuitionType']->id,
            'collection_mode' => 'remaining',
        ]));

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'القبض الجزئي للشهر غير متاح حالياً.']);
    }

    public function test_api_collect_blocks_first_half_mode_with_clear_message(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0);
        Sanctum::actingAs($data['cashier']);

        $res = $this->postJson('/api/payments/collect', [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'first_half',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 50.0],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['collection_mode']);

        $this->assertStringContainsString(
            'القبض الجزئي للشهر غير متاح حالياً.',
            $res->json('errors.collection_mode.0')
        );
    }

    public function test_api_collect_blocks_remaining_mode_with_clear_message(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0);
        Sanctum::actingAs($data['cashier']);

        $res = $this->postJson('/api/payments/collect', [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'remaining',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 50.0],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['collection_mode']);

        $this->assertStringContainsString(
            'القبض الجزئي للشهر غير متاح حالياً.',
            $res->json('errors.collection_mode.0')
        );
    }

    public function test_api_preview_and_collect_accept_preschool_for_september_with_manual_amount(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0, 'PRE1');
        Sanctum::actingAs($data['cashier']);

        // معاينة سبتمبر لقسم تحضيري/روضة عبر المسار القياسي تعيد 200 مع المعلوم المقترح
        $previewRes = $this->getJson('/api/payments/collect/preview?' . http_build_query([
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'fee_type_id' => $data['tuitionType']->id,
            'collection_mode' => 'full',
        ]));

        $previewRes->assertStatus(200);

        // استخلاص سبتمبر لقسم تحضيري/روضة عبر المسار القياسي بمبلغ يدوي 40 د.ت ينجح بـ 201
        $collectRes = $this->postJson('/api/payments/collect', [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'full',
            'manual_amount' => 40.0,
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 40.0],
            ],
        ]);

        $collectRes->assertStatus(201);
    }

    public function test_api_preview_and_collect_operate_normally_for_primary_level(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0, 'L1');
        Sanctum::actingAs($data['cashier']);

        // 1. معاينة عادية للمستوى الابتدائي (L1) تقبل سبتمبر
        $previewRes = $this->getJson('/api/payments/collect/preview?' . http_build_query([
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'fee_type_id' => $data['tuitionType']->id,
            'collection_mode' => 'full',
        ]));

        $previewRes->assertOk()
            ->assertJsonPath('remaining_amount', 100)
            ->assertJsonPath('collection_mode', 'full');

        // 2. استخلاص عادي للمستوى الابتدائي (L1) ينجح في سبتمبر
        $collectRes = $this->postJson('/api/payments/collect', [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'collection_mode' => 'full',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ]);

        $collectRes->assertCreated();
        $this->assertSame(1, Payment::where('enrollment_id', $data['enrollment']->id)->count());
        $this->assertSame(1, StudentFee::where('enrollment_id', $data['enrollment']->id)->count());
        $this->assertSame(1, CashTransaction::where('source_type', Payment::class)->count());
    }

    public function test_api_access_control_blocks_unauthorized_users(): void
    {
        $data = $this->setupPreschoolApiEnvironment(100.0);

        $res = $this->postJson('/api/payments/collect', [
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
        ]);

        $res->assertUnauthorized();
    }
}
