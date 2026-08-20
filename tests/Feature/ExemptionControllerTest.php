<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\MonthlyDiscount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExemptionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_1_get_exemptions_returns_both_monthly_and_club_exemptions(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // Monthly exemption
        $monthly = MonthlyDiscount::create([
            'enrollment_id'    => $enrollment->id,
            'academic_year_id' => $year->id,
            'discount_type'    => 'full_waiver',
            'start_month'      => '2025-09',
            'end_month'        => '2026-06',
            'reason'           => 'إعفاء اجتماعي كلي',
            'created_by'       => $user->id,
        ]);

        // Club exemption
        $club = $this->makeClub('الروبوتيك', 50.0);
        $sub = ClubSubscription::create([
            'student_id'       => $enrollment->student_id,
            'club_id'          => $club->id,
            'academic_year_id' => $year->id,
            'status'           => 'active',
            'start_date'       => '2025-09-01',
        ]);
        $clubDiscount = ClubMonthlyDiscount::create([
            'club_subscription_id' => $sub->id,
            'academic_year_id'     => $year->id,
            'discount_type'        => 'humanitarian_fixed',
            'monthly_amount'       => 30.0,
            'start_month'          => '2025-09',
            'end_month'            => '2026-05',
            'reason'               => 'تخفيض إنساني لنادي الروبوتيك',
            'created_by'           => $user->id,
        ]);

        $res = $this->getJson("/api/enrollments/{$enrollment->id}/exemptions");
        $res->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertCount(1, $res->json('monthly_exemptions'));
        $this->assertCount(1, $res->json('club_exemptions'));

        $this->assertSame('tuition', $res->json('monthly_exemptions.0.type'));
        $this->assertSame('full_waiver', $res->json('monthly_exemptions.0.discount_type'));
        $this->assertSame('club', $res->json('club_exemptions.0.type'));
        $this->assertSame(30.0, (float) $res->json('club_exemptions.0.monthly_amount'));
        $this->assertSame('الروبوتيك', $res->json('club_exemptions.0.club_name'));
    }

    public function test_2_store_monthly_full_waiver_succeeds(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 180.0);

        $payload = [
            'discount_type' => 'full_waiver',
            'start_month'   => '2025-09',
            'end_month'     => '2026-06',
            'reason'        => 'إعفاء تلميذ من ذوي الاحتياجات',
            'notes'         => 'ملاحظات إدارية',
        ];

        $res = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/monthly", $payload);
        $res->assertStatus(201);
        $res->assertJsonPath('data.discount_type', 'full_waiver');
        $res->assertJsonPath('data.type', 'tuition');
        $res->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('monthly_discounts', [
            'enrollment_id' => $enrollment->id,
            'discount_type' => 'full_waiver',
            'start_month'   => '2025-09',
            'end_month'     => '2026-06',
            'created_by'    => $user->id,
        ]);
    }

    public function test_3_store_monthly_humanitarian_fixed_validates_amount_and_range(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 180.0);

        // Invalid: end_month < start_month
        $resBadRange = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/monthly", [
            'discount_type'  => 'humanitarian_fixed',
            'monthly_amount' => 50.0,
            'start_month'    => '2026-01',
            'end_month'      => '2025-10',
            'reason'         => 'تخفيض إنساني',
        ]);
        $resBadRange->assertStatus(422);

        // Valid
        $resOk = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/monthly", [
            'discount_type'  => 'humanitarian_fixed',
            'monthly_amount' => 50.0,
            'start_month'    => '2025-10',
            'end_month'      => '2026-04',
            'reason'         => 'تخفيض إنساني صالح',
        ]);
        $resOk->assertStatus(201);
        $this->assertEquals(50.0, (float) $resOk->json('data.monthly_amount'));
    }

    public function test_4_prevent_overlapping_monthly_exemptions(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 180.0);

        MonthlyDiscount::create([
            'enrollment_id'    => $enrollment->id,
            'academic_year_id' => $year->id,
            'discount_type'    => 'full_waiver',
            'start_month'      => '2025-09',
            'end_month'        => '2026-01',
            'reason'           => 'إعفاء كلي سابق',
            'created_by'       => $user->id,
        ]);

        // Attempt overlap: 2025-11 to 2026-03
        $resOverlap = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/monthly", [
            'discount_type'  => 'humanitarian_fixed',
            'monthly_amount' => 60.0,
            'start_month'    => '2025-11',
            'end_month'      => '2026-03',
            'reason'         => 'تخفيض متداخل',
        ]);
        $resOverlap->assertStatus(422);
    }

    public function test_5_store_club_exemption_succeeds_and_verifies_student_link(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $club = $this->makeClub('الحساب الذهني', 40.0);
        $sub = ClubSubscription::create([
            'student_id'       => $enrollment->student_id,
            'club_id'          => $club->id,
            'academic_year_id' => $year->id,
            'status'           => 'active',
            'start_date'       => '2025-09-01',
        ]);

        $res = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/club/{$sub->id}", [
            'discount_type' => 'full_waiver',
            'start_month'   => '2025-09',
            'end_month'     => '2026-05',
            'reason'        => 'إعفاء نادي كامل',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.type', 'club');
        $res->assertJsonPath('data.club_name', 'الحساب الذهني');

        // Other student subscription should fail
        $otherStudent = Student::create([
            'student_code'        => 'OTHER-99',
            'first_name'          => 'طارق',
            'last_name'           => 'الهمامي',
            'gender'              => 'male',
            'status'              => 'active',
            'guardian_first_name' => 'ولي',
            'guardian_last_name'  => 'الهمامي',
            'guardian_phone'      => '99112233',
        ]);
        $otherSub = ClubSubscription::create([
            'student_id'       => $otherStudent->id,
            'club_id'          => $club->id,
            'academic_year_id' => $year->id,
            'status'           => 'active',
            'start_date'       => '2025-09-01',
        ]);

        $resBadSub = $this->postJson("/api/enrollments/{$enrollment->id}/exemptions/club/{$otherSub->id}", [
            'discount_type' => 'full_waiver',
            'start_month'   => '2025-09',
            'end_month'     => '2026-05',
            'reason'        => 'محاولة ربط اشتراك غير تابع للتلميذ',
        ]);
        $resBadSub->assertStatus(422);
    }

    public function test_6_delete_cancels_exemption_without_removing_record(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 180.0);

        $monthly = MonthlyDiscount::create([
            'enrollment_id'    => $enrollment->id,
            'academic_year_id' => $year->id,
            'discount_type'    => 'full_waiver',
            'start_month'      => '2025-09',
            'end_month'        => '2026-06',
            'reason'           => 'إعفاء تجريبي',
            'created_by'       => $user->id,
        ]);

        $res = $this->deleteJson("/api/exemptions/monthly/{$monthly->id}", [
            'reason' => 'إلغاء الإعفاء لانتفاء الموجب',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.is_active', false);
        $res->assertJsonPath('data.cancellation_reason', 'إلغاء الإعفاء لانتفاء الموجب');

        // Record must still exist in DB with cancelled_at set
        $this->assertDatabaseHas('monthly_discounts', [
            'id'                  => $monthly->id,
            'cancelled_by'        => $user->id,
            'cancellation_reason' => 'إلغاء الإعفاء لانتفاء الموجب',
        ]);
    }

    public function test_7_all_exemptions_endpoint_returns_stats_and_filters(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);

        // Tuition full waiver for student 1
        MonthlyDiscount::create([
            'enrollment_id'    => $enrollment1->id,
            'academic_year_id' => $year->id,
            'discount_type'    => 'full_waiver',
            'start_month'      => '2025-09',
            'end_month'        => '2026-06',
            'reason'           => 'إعفاء كلي طالب 1',
            'created_by'       => $user->id,
        ]);

        // Club full waiver for student 2
        $club = $this->makeClub('الحساب الذهني', 40.0);
        $sub = ClubSubscription::create([
            'student_id'       => $enrollment2->student_id,
            'club_id'          => $club->id,
            'academic_year_id' => $year->id,
            'status'           => 'active',
            'start_date'       => '2025-09-01',
        ]);
        ClubMonthlyDiscount::create([
            'club_subscription_id' => $sub->id,
            'academic_year_id'     => $year->id,
            'discount_type'        => 'full_waiver',
            'start_month'          => '2025-09',
            'end_month'            => '2026-05',
            'reason'               => 'إعفاء نادي كامل',
            'created_by'           => $user->id,
        ]);

        // Query all
        $res = $this->getJson("/api/exemptions?academic_year_id={$year->id}");
        $res->assertOk();
        $res->assertJsonPath('stats.total_exemptions', 2);
        $res->assertJsonPath('stats.tuition_full_waivers', 1);
        $res->assertJsonPath('stats.club_full_waivers', 1);
        $res->assertJsonPath('stats.humanitarian_discounts', 0);
        $this->assertCount(2, $res->json('data'));

        // Query filter by discount_type
        $resFilter = $this->getJson("/api/exemptions?academic_year_id={$year->id}&discount_type=full_waiver");
        $resFilter->assertOk();
        $this->assertCount(2, $resFilter->json('data'));
    }

    protected function makeWaiverUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'finance_manager'], ['display_name' => 'مسؤول المالية']);
        $p1 = Permission::firstOrCreate(['name' => 'waive_fees'], ['display_name' => 'إدارة التخفيضات والإعفاءات', 'group' => 'Finance']);
        $p2 = Permission::firstOrCreate(['name' => 'manage_students'], ['display_name' => 'إدارة التلاميذ', 'group' => 'Students']);
        $role->permissions()->syncWithoutDetaching([$p1->id, $p2->id]);

        $suffix = uniqid();
        $user = User::create([
            'username'   => 'waiver_mgr_' . $suffix,
            'email'      => 'waiver_mgr_' . $suffix . '@test.local',
            'first_name' => 'مدير',
            'last_name'  => 'الإعفاءات',
            'password'   => bcrypt('password123'),
            'role_id'    => $role->id,
            'is_active'  => true,
        ]);

        return $user;
    }

    protected function makeFeeCategory(string $code = 'TUITION', string $name = 'معاليم التمدرس'): FeeCategory
    {
        return FeeCategory::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_recurring' => true]
        );
    }

    protected function makeFeePlan(AcademicYear $year, int $levelId, float $amount = 160.0): FeePlan
    {
        $cat = $this->makeFeeCategory();
        return FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id'         => $levelId,
            'fee_category_id'  => $cat->id,
            'name'             => 'المعلوم الشهري',
            'amount'           => $amount,
            'frequency'        => 'monthly',
        ]);
    }

    protected function makeClub(string $name = 'نادي', float $monthlyFee = 80.0): Club
    {
        $cat = $this->makeFeeCategory('CLUB', 'معاليم النوادي');
        return Club::create([
            'name'            => $name,
            'fee_category_id' => $cat->id,
            'monthly_fee'     => $monthlyFee,
            'is_active'       => true,
        ]);
    }
}
