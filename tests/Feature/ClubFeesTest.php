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
use Database\Seeders\ClubSeeder;
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
        $this->seed(ClubSeeder::class);
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

    /** 1. واجهة النوادي النشطة تعيد ناديي الحساب الذهني والروبوتيك. */
    public function test_active_clubs_api_returns_mental_arithmetic_and_robotics_clubs(): void
    {
        $user = $this->makeUserWithPermissions('manager', ['manage_students']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/clubs');
        $response->assertOk();

        $names = collect($response->json())->pluck('name')->all();
        $this->assertContains('الحساب الذهني', $names);
        $this->assertContains('الروبوتيك', $names);
    }

    /** 2. اختيار نادي الحساب الذهني يعيد التلاميذ الصحيحين برقم النادي. */
    public function test_selecting_mental_arithmetic_club_returns_correct_students(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $mentalClub = Club::where('name', 'الحساب الذهني')->firstOrFail();

        $report = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'club_id' => $mentalClub->id,
            'section_id' => $enrollment->section_id,
        ]);

        $this->assertEquals(1, count($report['records']));
        $this->assertEquals('الحساب الذهني', $report['records'][0]['club_name']);
        $this->assertEquals($enrollment->student_id, $report['records'][0]['student_id']);
    }

    /** 3. اختيار نادي الروبوتيك يعيد التلاميذ الصحيحين برقم النادي. */
    public function test_selecting_robotics_club_returns_correct_students(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $roboticsClub = Club::where('name', 'الروبوتيك')->firstOrFail();

        $report = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'club_id' => $roboticsClub->id,
            'section_id' => $enrollment->section_id,
        ]);

        $this->assertEquals(1, count($report['records']));
        $this->assertEquals('الروبوتيك', $report['records'][0]['club_name']);
        $this->assertEquals($enrollment->student_id, $report['records'][0]['student_id']);
    }

    /** 4. الفلترة حسب المستوى تعمل وتجلب تلاميذ المستوى فقط. */
    public function test_filtering_by_level_works(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year); // another level

        $mentalClub = Club::where('name', 'الحساب الذهني')->firstOrFail();

        $report = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'club_id' => $mentalClub->id,
            'level_id' => $enrollment1->level_id,
        ]);

        $studentIds = collect($report['records'])->pluck('student_id')->all();
        $this->assertContains($enrollment1->student_id, $studentIds);
        $this->assertNotContains($enrollment2->student_id, $studentIds);
    }

    /** 5. قبول status=all وحالة الدفع الخالية في فلتر التقرير دون خطأ 422. */
    public function test_status_all_and_empty_status_pass_validation_and_return_all_records(): void
    {
        $user = $this->makeUserWithPermissions('accountant', ['manage_payments']);
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $resAll = $this->getJson("/api/reports/club-fees?month=2026-05&academic_year_id={$year->id}&status=all");
        $resAll->assertOk();
        $this->assertEquals(1, count($resAll->json('records')));

        $resNoStatus = $this->getJson("/api/reports/club-fees?month=2026-05&academic_year_id={$year->id}");
        $resNoStatus->assertOk();
        $this->assertEquals(1, count($resNoStatus->json('records')));
    }

    /** 6. عملية توليد سجلات الشهر دون تصفية حالة تنجح بنجاح. */
    public function test_generation_with_no_status_filter_succeeds(): void
    {
        $user = $this->makeUserWithPermissions('accountant', ['manage_payments']);
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $res = $this->postJson('/api/reports/club-fees/generate', [
            'academic_year_id' => $year->id,
            'month' => '2026-05',
            'club_id' => $club->id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('result.created', 1);
    }

    /** 7. الفلترة حسب القسم تعيد كافة التلاميذ النشطين في هذا القسم. */
    public function test_filtering_by_section_displays_all_active_students_in_that_section(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year, null);

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

    /** 8. كل تلميذ يعرض حديثاً يبدأ بحالة 'في انتظار الدفع' ولون برتقالي. */
    public function test_every_newly_displayed_student_starts_as_orange_pending(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الموسيقى 2', 'monthly_fee' => 60.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals('unpaid', $report['records'][0]['status']);
        $this->assertEquals('في انتظار الدفع', $report['records'][0]['status_label']);
        $this->assertEquals('orange', $report['records'][0]['status_color']);
    }

    /** 9. التلميذ الذي دفع بالكامل يصبح 'خلاص كامل' ولون أخضر. */
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

    /** 10. الاسم والقسم يأتيان تلقائياً من بيانات التلميذ والتسجيل الموجودة. */
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

    /** 11. المسعف/المالك يستطيع استبعاد تلميذ من النادي بعد سبتمبر. */
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

    /** 12. المستخدم غير المخول لا يستطيع استبعاد أو إعادة تلميذ. */
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

    /** 13. الاستبعاد لا يحذف التلميذ ولا المدفوعات السابقة. */
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

        $this->clubService->excludeStudent($sub, $admin->id, 'توقف عن الدراسة بالنادي');

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('club_monthly_fees', ['id' => $septFee->id, 'status' => 'paid']);
    }

    /** 14. التلميذ المستبعد لا يظهر في الأشهر الجديدة المولدة. */
    public function test_excluded_students_do_not_appear_in_newly_generated_months(): void
    {
        $admin = $this->makeUserWithPermissions('admin', ['manage_students']);
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub();

        $sub = $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-09');

        $this->clubService->excludeStudent($sub, $admin->id);

        $this->clubService->generateMonthFees($year->id, '2026-10');

        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollment->student_id,
            'club_id' => $club->id,
            'month' => '2026-10',
        ]);
    }

    /** 15. الإجماليات تحدّث بعد الاستخلاص وتطابق الجدول المفلتر. */
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

        $fee1 = ClubMonthlyFee::where('student_id', $enrollment1->student_id)->first();
        $this->clubService->recordPayment($fee1, 40.00, '2026-05-10', 'cash');

        $reportAfter = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);
        $this->assertEquals(1, $reportAfter['summary']['paid_count']);
        $this->assertEquals(1, $reportAfter['summary']['pending_count']);
    }

    /** 16. بطاقة الاستقبال في اللوحة تعرض مداخيل النوادي ولا تضاعف الاستخلاص عند التكرار. */
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
    }

    /** 17. توليد سجلات كل النوادي يعتمد على اشتراكات النوادي الفعلية. */
    public function test_all_clubs_generation_uses_existing_subscriptions(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club1 = $this->makeClub(['name' => 'نادي الموسيقى 3']);
        $club2 = $this->makeClub(['name' => 'نادي الرسم 3']);

        $this->clubService->subscribeStudent($enrollment->student_id, $club1->id, $year->id);
        $this->clubService->subscribeStudent($enrollment->student_id, $club2->id, $year->id);

        $result = $this->clubService->generateMonthFees($year->id, '2026-05', null);

        $this->assertEquals(2, $result['created']);
        $this->assertEquals(2, ClubMonthlyFee::where('student_id', $enrollment->student_id)->where('month', '2026-05')->count());
    }
}
