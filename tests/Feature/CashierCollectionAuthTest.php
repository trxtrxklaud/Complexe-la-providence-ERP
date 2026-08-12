<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierCollectionAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_access_collection_years(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/collection/years')->assertOk();
    }

    public function test_cashier_can_access_sections_for_year(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);
        $year = $this->makeAcademicYear();

        $this->getJson("/api/collection/years/{$year->id}/sections")->assertOk();
    }

    public function test_cashier_can_access_students_by_section(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);
        $enrollment = $this->makeEnrollment();

        $this->getJson("/api/collection/sections/{$enrollment->section_id}/students?year_id={$enrollment->academic_year_id}")
            ->assertOk();
    }

    public function test_cashier_can_access_enrollment_ledger(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);
        $enrollment = $this->makeEnrollment();

        $this->getJson("/api/enrollments/{$enrollment->id}/ledger")->assertOk();
    }

    public function test_cashier_can_collect_payment(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $payload = [
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-15',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 240.0],
            ],
        ];

        $response = $this->postJson('/api/payments/collect', $payload);

        $response->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل الاستخلاص بنجاح')
            ->assertJsonStructure(['receipt']);
    }

    public function test_cashier_can_cancel_own_payment(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $payload = [
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-15',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 240.0],
            ],
        ];

        $receipt = $this->postJson('/api/payments/collect', $payload)->json('receipt');
        $paymentId = $receipt['payment_id'];

        // القابض يملك إلغاء الوصل: manage_payments يحرس المسار.
        $this->postJson("/api/payments/{$paymentId}/cancel", ['reason' => 'خطأ في المبلغ'])
            ->assertOk();
    }

    public function test_cashier_cannot_access_treasury_balance(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/treasury/balance')->assertForbidden();
    }

    public function test_cashier_cannot_access_treasury_history(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/treasury/history')->assertForbidden();
    }

    public function test_cashier_cannot_access_net_income_report(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/reports/net-income')->assertForbidden();
    }

    public function test_cashier_cannot_access_treasury_daybook(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/reports/treasury-daybook')->assertForbidden();
    }

    public function test_cashier_cannot_access_expenses_report(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/reports/expenses')->assertForbidden();
    }

    private function makeCashier(): User
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);

        $permissions = ['manage_payments', 'manage_students', 'view_students'];

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user->fresh(['role.permissions']);
    }
}
