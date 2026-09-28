<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\MonthlyDiscount;
use App\Models\Permission;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscountRosterTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaiverUser()
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        $permission = Permission::firstOrCreate([
            'name' => 'waive_fees',
        ], [
            'display_name' => 'التنازل عن الدُّيون',
            'group' => 'Finance',
        ]);
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }

    private function makeFeePlan(AcademicYear $year, int $levelId, float $amount = 100.0): FeePlan
    {
        $cat = FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'معاليم الدراسة', 'is_recurring' => true]
        );

        return FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id'         => $levelId,
            'fee_category_id'  => $cat->id,
            'name'             => 'القسط الشهري',
            'amount'           => $amount,
            'frequency'        => 'monthly',
        ]);
    }

    public function test_roster_options_returns_sections_and_years(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/discounts/roster-options');
        $response->assertOk()
            ->assertJsonStructure(['sections', 'years', 'active_year_id', 'months']);
    }

    public function test_roster_endpoint_returns_section_students_and_10_months(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 100.0);

        $response = $this->getJson('/api/discounts/sections/' . $enrollment->section_id . '/roster?academic_year_id=' . $year->id);
        $response->assertOk()
            ->assertJsonPath('reference_monthly_fee', fn ($v) => (float) $v === 100.0)
            ->assertJsonPath('discount_cap', fn ($v) => (float) $v === 20.0)
            ->assertJsonCount(10, 'months')
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.enrollment_id', $enrollment->id);
    }

    public function test_apply_discount_single_month_alone(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 100.0);

        // Apply discount to October only (2025-10)
        $response = $this->postJson('/api/discounts/roster/apply', [
            'enrollment_id'  => $enrollment->id,
            'scope'          => 'single_month',
            'target_month'   => '2025-10',
            'discount_type'  => 'normal_monthly',
            'monthly_amount' => 15.0,
            'reason'         => 'تخفيض شهر أكتوبر فقط',
        ]);

        $response->assertOk();

        // Check roster
        $roster = $this->getJson('/api/discounts/sections/' . $enrollment->section_id . '/roster?academic_year_id=' . $year->id);
        $roster->assertOk();

        $row = $roster->json('rows.0');
        $this->assertTrue($row['months']['2025-10']['has_discount']);
        $this->assertEquals(15.0, $row['months']['2025-10']['amount']);
        $this->assertFalse($row['months']['2025-09']['has_discount']);
        $this->assertFalse($row['months']['2025-11']['has_discount']);
    }

    public function test_apply_single_month_inside_existing_range_preserves_surrounding_months(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 100.0);

        // 1. Give whole year 10 TND discount
        $this->postJson('/api/discounts/roster/apply', [
            'enrollment_id'  => $enrollment->id,
            'scope'          => 'full_year',
            'discount_type'  => 'normal_monthly',
            'monthly_amount' => 10.0,
            'reason'         => 'تخفيض سنوي',
        ])->assertOk();

        // 2. Modify October (2025-10) to full waiver
        $this->postJson('/api/discounts/roster/apply', [
            'enrollment_id' => $enrollment->id,
            'scope'         => 'single_month',
            'target_month'  => '2025-10',
            'discount_type' => 'full_waiver',
            'reason'        => 'إعفاء خاص بأكتوبر',
        ])->assertOk();

        // 3. Check roster:
        // September (2025-09) must still be 10.0 TND!
        // October (2025-10) must be 100.0 TND (full waiver)!
        // November (2025-11) must still be 10.0 TND!
        $roster = $this->getJson('/api/discounts/sections/' . $enrollment->section_id . '/roster?academic_year_id=' . $year->id);
        $roster->assertOk();

        $row = $roster->json('rows.0');
        $this->assertTrue($row['months']['2025-09']['has_discount']);
        $this->assertEquals(10.0, $row['months']['2025-09']['amount']);

        $this->assertTrue($row['months']['2025-10']['has_discount']);
        $this->assertEquals('full_waiver', $row['months']['2025-10']['discount_type']);
        $this->assertEquals(100.0, $row['months']['2025-10']['amount']);

        $this->assertTrue($row['months']['2025-11']['has_discount']);
        $this->assertEquals(10.0, $row['months']['2025-11']['amount']);
    }

    public function test_remove_discount_for_single_month_alone(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 100.0);

        // Give whole year 15 TND discount
        $this->postJson('/api/discounts/roster/apply', [
            'enrollment_id'  => $enrollment->id,
            'scope'          => 'full_year',
            'discount_type'  => 'normal_monthly',
            'monthly_amount' => 15.0,
            'reason'         => 'تخفيض سنوي',
        ])->assertOk();

        // Remove discount for October only
        $this->postJson('/api/discounts/roster/remove', [
            'enrollment_id' => $enrollment->id,
            'target_month'  => '2025-10',
            'reason'        => 'إلغاء تخفيض أكتوبر فقط',
        ])->assertOk();

        $roster = $this->getJson('/api/discounts/sections/' . $enrollment->section_id . '/roster?academic_year_id=' . $year->id);
        $roster->assertOk();

        $row = $roster->json('rows.0');
        $this->assertTrue($row['months']['2025-09']['has_discount']);
        $this->assertEquals(15.0, $row['months']['2025-09']['amount']);

        // October has NO discount!
        $this->assertFalse($row['months']['2025-10']['has_discount']);

        // November still has discount!
        $this->assertTrue($row['months']['2025-11']['has_discount']);
        $this->assertEquals(15.0, $row['months']['2025-11']['amount']);
    }

    public function test_unauthorized_user_forbidden(): void
    {
        $regularUser = $this->makeUser('cashier');
        $regularUser->update(['is_active' => true]);
        Sanctum::actingAs($regularUser);

        $response = $this->getJson('/api/discounts/roster-options');
        $response->assertForbidden();
    }
}
