<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Permission;
use App\Models\Student;
use App\Models\User;
use App\Services\ClubService;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubFeesTest extends TestCase
{
    use RefreshDatabase;

    private ClubService $clubService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clubService = app(ClubService::class);
    }

    private function makeUserWithPermissions(string $roleName, array $permissions): User
    {
        $user = $this->makeUser($roleName);
        $user->update(['is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user;
    }

    private function makeClub(array $attributes = []): Club
    {
        $category = \App\Models\FeeCategory::firstOrCreate(
            ['code' => 'CLUB'],
            ['name' => 'معاليم النوادي', 'is_recurring' => true]
        );

        return Club::create(array_merge([
            'name' => 'نادي الشطرنج',
            'fee_category_id' => $category->id,
            'monthly_fee' => 50.00,
            'is_active' => true,
        ], $attributes));
    }

    /** 1. الفلترة حسب القسم تعيد كافة التلاميذ النشطين في هذا القسم. */
    public function test_filtering_by_section_displays_all_active_students_in_that_section(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year, null); // In different section or same section

        // Make both enrollments belong to section 1
        $enrollment2->update(['section_id' => $enrollment1->section_id]);

        $club = $this->makeClub();
        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);

        $report = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'section_id' => $enrollment1->section_id,
            'club_id' => $club->id,
        ]);

        $studentIds = collect($report['records'])->pluck('student_id')->all();
        $this->assertContains($enrollment1->student_id, $studentIds);
        $this->assertContains($enrollment2->student_id, $studentIds);
    }

    /** 2. كل تلميذ يعرض حديثاً يبدأ بحالة 'في انتظار الدفع' ولون برتقالي. */
    public function test_every_newly_displayed_student_starts_as_orange_pending(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الموسيقى', 'monthly_fee' => 60.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals('unpaid', $report['records'][0]['status']);
        $this->assertEquals('في انتظار الدفع', $report['records'][0]['status_label']);
        $this->assertEquals('orange', $report['records'][0]['status_color']);
    }

    /** 3. التلميذ الذي دفع بالكامل يصبح 'خلاص كامل' ولون أخضر. */
    public function test_fully_paid_student_becomes_green_paid(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $feeRecord = ClubMonthlyFee::where('student_id', $enrollment->student_id)->first();
        $this->clubService->recordPayment($feeRecord, 50.00, '2026-05-10', 'cash');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals('paid', $report['records'][0]['status']);
        $this->assertEquals('خلاص كامل', $report['records'][0]['status_label']);
        $this->assertEquals('green', $report['records'][0]['status_color']);
    }

    /** 4. الاسم والقسم يأتيان تلقائياً من بيانات التلميذ والتسجيل الموجودة. */
    public function test_student_name_and_section_come_automatically_from_existing_student_enrollment_data(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $student = $enrollment->student;
        $club = $this->makeClub();

        $this->clubService->subscribeStudent($student->id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);
        $firstRecord = $report['records'][0];

        $this->assertEquals(trim($student->first_name . ' ' . $student->last_name), $firstRecord['student_name']);
        $this->assertEquals($enrollment->section->name, $firstRecord['section_name']);
    }

    /** 5. الاستخلاص يستخدم اسم النادي المعرف مسبقاً. */
    public function test_collection_uses_exact_existing_club_name(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الروبوتيك الإشرافي']);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals('نادي الروبوتيك الإشرافي', $report['records'][0]['club_name']);
    }

    /** 6. المسعف/المالك يستطيع استبعاد تلميذ من النادي بعد سبتمبر. */
    public function test_owner_admin_can_exclude_student_after_september(): void
    {
        $user = $this->makeUserWithPermissions('admin', ['manage_students']);
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $sub = $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $response = $this->postJson("/api/club-subscriptions/{$sub->id}/exclude", [
            'reason' => 'مغادرة النادي بعد سبتمبر',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('club_subscriptions', [
            'id' => $sub->id,
            'status' => 'cancelled',
            'excluded_by' => $user->id,
        ]);
    }

    /** 7. المستخدم غير المخول لا يستطيع استبعاد أو إعادة تلميذ. */
    public function test_unauthorized_users_cannot_exclude_or_restore_student(): void
    {
        $guest = $this->makeUserWithPermissions('guest', []);
        Sanctum::actingAs($guest);

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $sub = $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $this->postJson("/api/club-subscriptions/{$sub->id}/exclude")->assertStatus(403);
        $this->postJson("/api/club-subscriptions/{$sub->id}/restore")->assertStatus(403);
    }

    /** 8. الاستبعاد لا يحذف التلميذ ولا المدفوعات السابقة. */
    public function test_exclusion_does_not_delete_student_or_historical_payments(): void
    {
        $admin = $this->makeUserWithPermissions('admin', ['manage_students']);
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $student = $enrollment->student;
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $sub = $this->clubService->subscribeStudent($student->id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-09');

        $septFee = ClubMonthlyFee::where('month', '2026-09')->first();
        $this->clubService->recordPayment($septFee, 50.00, '2026-09-10', 'cash');

        // Exclude student
        $this->clubService->excludeStudent($sub, $admin->id, 'توقف عن الدراسة بالنادي');

        // Student & enrollment & payment remain in DB
        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('club_monthly_fees', ['id' => $septFee->id, 'status' => 'paid']);
    }

    /** 9. التلميذ المستبعد لا يظهر في الأشهر الجديدة المولدة. */
    public function test_excluded_students_do_not_appear_in_newly_generated_months(): void
    {
        $admin = $this->makeUserWithPermissions('admin', ['manage_students']);
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $sub = $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-09');

        // Exclude in October
        $this->clubService->excludeStudent($sub, $admin->id);

        // Generate October
        $this->clubService->generateMonthFees($year->id, '2026-10');

        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollment->student_id,
            'club_id' => $club->id,
            'month' => '2026-10',
        ]);
    }

    /** 10. التقارير السابقة تحتفظ بالتلميذ المستبعد مع مدفوعاته. */
    public function test_previous_monthly_reports_still_contain_excluded_students(): void
    {
        $admin = $this->makeUserWithPermissions('admin', ['manage_students']);
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $sub = $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-09');

        $septFee = ClubMonthlyFee::where('month', '2026-09')->first();
        $this->clubService->recordPayment($septFee, 50.00, '2026-09-10', 'cash');

        $this->clubService->excludeStudent($sub, $admin->id);

        $septReport = $this->clubService->getReport(['month' => '2026-09', 'academic_year_id' => $year->id]);
        $this->assertEquals(1, count($septReport['records']));
        $this->assertEquals('paid', $septReport['records'][0]['status']);
    }

    /** 11 & 12. الإجماليات تحدّث بعد الاستخلاص وتطابق الجدول المفلتر. */
    public function test_totals_update_after_payment_and_match_filtered_table(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 40.00]);

        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);
        $this->clubService->subscribeStudent($enrollment2->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $reportBefore = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);
        $this->assertEquals(0, $reportBefore['summary']['paid_count']);
        $this->assertEquals(2, $reportBefore['summary']['pending_count']);
        $this->assertEquals(80.00, $reportBefore['summary']['total_due']);
        $this->assertEquals(0.00, $reportBefore['summary']['total_paid']);

        // Collect payment for student 1
        $fee1 = ClubMonthlyFee::where('student_id', $enrollment1->student_id)->first();
        $this->clubService->recordPayment($fee1, 40.00, '2026-05-10', 'cash');

        $reportAfter = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);
        $this->assertEquals(1, $reportAfter['summary']['paid_count']);
        $this->assertEquals(1, $reportAfter['summary']['pending_count']);
        $this->assertEquals(40.00, $reportAfter['summary']['total_paid']);
        $this->assertEquals(40.00, $reportAfter['summary']['total_remaining']);
    }

    /** 13 & 14. بطاقة الاستقبال في اللوحة تعرض مداخيل النوادي ولا تضاعف الاستخلاص عند التكرار. */
    public function test_reception_dashboard_card_shows_current_club_revenue_without_double_counting(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 70.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $currentMonth = now()->format('Y-m');
        $this->clubService->generateMonthFees($year->id, $currentMonth);

        $fee = ClubMonthlyFee::where('month', $currentMonth)->first();
        $this->clubService->recordPayment($fee, 70.00, now()->toDateString(), 'cash');

        $dashboardService = app(DashboardService::class);
        $data = $dashboardService->getDashboardData(true);

        $this->assertArrayHasKey('club_revenue', $data);
        $this->assertEquals(70.00, $data['club_revenue']['collected_amount']);
        $this->assertEquals(1, $data['club_revenue']['paid_students_count']);

        // Re-recording / updating payment for same fee should not double count
        $this->clubService->recordPayment($fee, 70.00, now()->toDateString(), 'cash');

        $dataRetry = $dashboardService->getDashboardData(true);
        $this->assertEquals(70.00, $dataRetry['club_revenue']['collected_amount']);
    }

    /** 15. منع التكرار للسجلات بنفس التلميذ والشهر والسنة. */
    public function test_duplicate_record_prevention_for_same_student_club_month_year(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 80.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $res1 = $this->clubService->generateMonthFees($year->id, '2026-05');
        $res2 = $this->clubService->generateMonthFees($year->id, '2026-05');

        $this->assertEquals(1, $res1['created']);
        $this->assertEquals(0, $res2['created']);
        $this->assertEquals(1, $res2['skipped']);
    }

    /** 16. تغيير سعر النادي لا يغير السجلات القديمة. */
    public function test_updating_club_fee_does_not_modify_past_month_records(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 100.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $mayRecord = ClubMonthlyFee::where('month', '2026-05')->first();
        $this->assertEquals(100.00, (float) $mayRecord->amount_due);

        $club->update(['monthly_fee' => 120.00]);
        $this->clubService->generateMonthFees($year->id, '2026-06');

        $mayRecordFresh = ClubMonthlyFee::where('month', '2026-05')->first();
        $juneRecord = ClubMonthlyFee::where('month', '2026-06')->first();

        $this->assertEquals(100.00, (float) $mayRecordFresh->amount_due);
        $this->assertEquals(120.00, (float) $juneRecord->amount_due);
    }
}
