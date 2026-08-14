<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private function oldDebtSetup(float $amount = 200.0): array
    {
        $oldYear = AcademicYear::create([
            'name' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_active' => false,
        ]);
        $targetYear = $this->makeAcademicYear('2025-2026');
        $student = Student::create([
            'student_code' => 'STU-REQ-'.uniqid(),
            'first_name' => 'ليلى',
            'last_name' => 'النجار',
            'gender' => 'female',
            'status' => 'active',
        ]);
        $oldEnrollment = $this->makeEnrollment($oldYear, $student);
        $targetEnrollment = $this->makeEnrollment($targetYear, $student);
        $oldFee = StudentFee::create([
            'enrollment_id' => $oldEnrollment->id,
            'fee_plan_id' => null,
            'description' => 'دين قديم للاختبار',
            'amount_due' => $amount,
            'due_date' => '2025-06-05',
            'status' => 'pending',
        ]);
        $openingBalance = OpeningBalance::create([
            'student_id' => $student->id,
            'source_enrollment_id' => $oldEnrollment->id,
            'source_student_fee_id' => $oldFee->id,
            'academic_year_id' => $targetYear->id,
            'amount' => $amount,
            'status' => OpeningBalance::STATUS_PENDING,
        ]);

        return [$student, $oldFee, $openingBalance, $targetEnrollment];
    }

    private function paymentUser()
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        $permission = Permission::firstOrCreate([
            'name' => 'manage_payments',
        ], [
            'display_name' => 'إدارة الاستخلاص',
            'group' => 'Finance',
        ]);
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);
        return $user;
    }

    private function basePayload(Enrollment $enrollment, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
        ], $overrides);
    }

    public function test_accepts_prior_allocation_by_student_fee_id_only(): void
    {
        [$student, $oldFee, $openingBalance, $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $response = $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 80]],
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => Payment::first()->id,
            'student_fee_id' => $oldFee->id,
            'amount_allocated' => 80,
        ]);
        $this->assertSame($student->id, Payment::first()->student_id);
    }

    public function test_accepts_prior_allocation_by_opening_balance_id_only(): void
    {
        [$student, $oldFee, $openingBalance, $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $response = $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [['opening_balance_id' => $openingBalance->id, 'amount' => 75]],
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('payment_allocations', [
            'student_fee_id' => $oldFee->id,
            'amount_allocated' => 75,
        ]);
        $this->assertSame($student->id, Payment::first()->student_id);
    }

    public function test_rejects_prior_allocation_with_both_targets(): void
    {
        [, $oldFee, $openingBalance, $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [[
                'student_fee_id' => $oldFee->id,
                'opening_balance_id' => $openingBalance->id,
                'amount' => 50,
            ]],
        ]))->assertStatus(422);
    }

    public function test_rejects_prior_allocation_without_a_target(): void
    {
        [, , , $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [['amount' => 50]],
        ]))->assertStatus(422);
    }

    public function test_rejects_prior_target_belonging_to_another_student(): void
    {
        [, $oldFee, , $enrollment] = $this->oldDebtSetup();
        $otherStudent = Student::create([
            'student_code' => 'STU-OTHER-'.uniqid(),
            'first_name' => 'سارة',
            'last_name' => 'الزهراء',
            'gender' => 'female',
            'status' => 'active',
        ]);
        $this->actingAs($this->paymentUser());

        $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'student_id' => $otherStudent->id,
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 50]],
        ]))->assertStatus(422);
    }

    public function test_rejects_prior_allocation_larger_than_outstanding(): void
    {
        [, $oldFee, , $enrollment] = $this->oldDebtSetup(100.0);
        $this->actingAs($this->paymentUser());

        $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 101]],
        ]))->assertStatus(422);
    }

    public function test_allows_old_debt_only_without_months(): void
    {
        [, $oldFee, , $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $response = $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ]));

        $response->assertCreated();
        $this->assertSame(200.0, (float) Payment::first()->amount);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_allows_mixed_current_and_prior_payment(): void
    {
        [, $oldFee, , $enrollment] = $this->oldDebtSetup();
        $feeType = $this->makeFeeType('القسط الشهري', 240);
        $this->actingAs($this->paymentUser());

        $response = $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 240]],
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 50]],
        ]));

        $response->assertCreated();
        $this->assertSame(290.0, (float) Payment::first()->amount);
        $this->assertSame(290.0, (float) PaymentAllocation::sum('amount_allocated'));
    }

    public function test_rejects_request_without_items_and_without_prior_allocations(): void
    {
        [, , , $enrollment] = $this->oldDebtSetup();
        $this->actingAs($this->paymentUser());

        $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'months' => ['2025-09'],
        ]))->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_legacy_monthly_request_remains_valid(): void
    {
        [, , , $enrollment] = $this->oldDebtSetup();
        $feeType = $this->makeFeeType('القسط الشهري', 240);
        $this->actingAs($this->paymentUser());

        $response = $this->postJson('/api/payments/collect', $this->basePayload($enrollment, [
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]));

        $response->assertCreated();
        $this->assertSame(240.0, (float) Payment::first()->amount);
        $this->assertDatabaseCount('payment_allocations', 1);
    }
}
