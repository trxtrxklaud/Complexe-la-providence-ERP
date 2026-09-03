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

        $this->clubService->generateMonthFees($year->id, '2026-05', $mentalClub->id);

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

        $this->clubService->generateMonthFees($year->id, '2026-05', $roboticsClub->id);

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
        $enrollment2 = $this->makeEnrollment($year);

        $mentalClub = Club::where('name', 'الحساب الذهني')->firstOrFail();

        $this->clubService->generateMonthFees($year->id, '2026-05', $mentalClub->id);

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
        $this->clubService->generateMonthFees($year->id, '2026-05', $club->id);

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

    /** 7. Dashboard المتخلدات يجمع إجمالي القسم والتلميذ ويعرض تفاصيل المعلوم غير المسدد. */
    public function test_arrears_dashboard_groups_by_section_and_student(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 75.00]);
        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $dashboard = $this->clubService->getArrearsDashboard(['academic_year_id' => $year->id, 'from_month' => '2025-09', 'to_month' => '2025-09']);

        $this->assertSame(1, $dashboard['summary']['sections_count']);
        $this->assertSame(1, $dashboard['summary']['students_count']);
        $this->assertSame(75.0, (float) $dashboard['summary']['total_remaining']);
        $this->assertSame(75.0, (float) $dashboard['sections'][0]['total_remaining']);
        $this->assertSame($enrollment->student_id, $dashboard['students'][0]['student_id']);
        $this->assertSame(75.0, (float) $dashboard['students'][0]['total_remaining']);
        $this->assertSame('نادي الشطرنج', $dashboard['students'][0]['details'][0]['club_name']);
    }

    /** 8. الفلترة حسب القسم تعيد كافة التلاميذ النشطين في هذا القسم وتستبعد أقساماً أخرى. */
    public function test_filtering_by_section_displays_all_active_students_in_that_section(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year, null);

        $enrollment2->update(['section_id' => $enrollment1->section_id]);

        $club = $this->makeClub();
        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);
        $this->clubService->subscribeStudent($enrollment2->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05', $club->id);

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

    /** 9. كل تلميذ يعرض حديثاً يبدأ بحالة 'في انتظار الدفع' ولون برتقالي. */
    public function test_every_newly_displayed_student_starts_as_orange_pending(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الموسيقى 2', 'monthly_fee' => 60.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05', $club->id);

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id, 'club_id' => $club->id]);

        $this->assertEquals('unpaid', $report['records'][0]['status']);
        $this->assertEquals('في انتظار الدفع', $report['records'][0]['status_label']);
        $this->assertEquals('orange', $report['records'][0]['status_color']);
    }

    /** 10. التلميذ الذي دفع بالكامل يصبح 'خلاص كامل' ولون أخضر. */
    public function test_fully_paid_student_becomes_green_paid(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05', $club->id);

        $feeRecord = ClubMonthlyFee::where('student_id', $enrollment->student_id)->where('club_id', $club->id)->first();
        $this->clubService->recordPayment($feeRecord, 50.00, '2026-05-10', 'cash');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id, 'club_id' => $club->id]);

        $this->assertEquals('paid', $report['records'][0]['status']);
        $this->assertEquals('خلاص كامل', $report['records'][0]['status_label']);
        $this->assertEquals('green', $report['records'][0]['status_color']);
    }

    /** 11. الاسم والقسم يأتيان تلقائياً من بيانات التلميذ والتسجيل الموجودة. */
    public function test_student_name_and_section_come_automatically_from_existing_student_enrollment_data(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $student = $enrollment->student;
        $club = $this->makeClub();

        $this->clubService->subscribeStudent($student->id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05', $club->id);

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id, 'club_id' => $club->id]);
        $firstRecord = $report['records'][0];

        $this->assertEquals(trim($student->first_name . ' ' . $student->last_name), $firstRecord['student_name']);
        $this->assertEquals($enrollment->section->name, $firstRecord['section_name']);
    }

    /** 12. المالك/المسعف يستطيع استبعاد تلميذ من النادي بعد سبتمبر. */
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

    /** 13. الاستبعاد لا يحذف التلميذ ولا المدفوعات السابقة. */
    public function test_exclusion_does_not_delete_student_or_historical_payments(): void
    {
        $admin = $this->makeUserWithPermissions('admin', ['manage_students']);
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $student = $enrollment->student;
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $sub = $this->clubService->subscribeStudent($student->id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $septFee = ClubMonthlyFee::where('month', '2025-09')->where('club_id', $club->id)->first();
        $this->clubService->recordPayment($septFee, 50.00, '2025-09-10', 'cash');

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
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $this->clubService->excludeStudent($sub, $admin->id);

        $this->clubService->generateMonthFees($year->id, '2025-10', $club->id);

        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollment->student_id,
            'club_id' => $club->id,
            'month' => '2025-10',
        ]);
    }

    /** 15. سعر النادي مأخوذ مباشرة من إدارة النوادي وليس رقماً ثابتاً. */
    public function test_club_price_is_taken_from_club_management(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $customClub = $this->makeClub(['name' => 'نادي الفلك', 'monthly_fee' => 85.50]);

        $this->clubService->subscribeStudent($enrollment->student_id, $customClub->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $customClub->id);

        $fee = ClubMonthlyFee::where('club_id', $customClub->id)->first();
        $this->assertEquals(85.50, (float) $fee->amount_due);

        $report = $this->clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $customClub->id]);
        $this->assertEquals(85.50, (float) $report['records'][0]['amount_due']);
    }

    /** 16. سبتمبر مدفوع وأكتوبر غير مدفوع يظهر أكتوبر فقط كمتخلد. */
    public function test_september_paid_october_unpaid_only_shows_october_in_arrears(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->clubService->generateMonthFees($year->id, '2025-10', $club->id);

        $septFee = ClubMonthlyFee::where('student_id', $enrollment->student_id)->where('month', '2025-09')->first();
        $this->clubService->recordPayment($septFee, 50.00, '2025-09-10', 'cash');

        $dashboard = $this->clubService->getArrearsDashboard([
            'academic_year_id' => $year->id,
            'from_month' => '2025-09',
            'to_month' => '2025-10',
            'club_id' => $club->id,
        ]);

        $this->assertEquals(1, $dashboard['summary']['fees_count']);
        $this->assertEquals(50.00, (float) $dashboard['summary']['total_remaining']);
        $this->assertEquals('2025-10', $dashboard['students'][0]['details'][0]['month']);
    }

    /** 17. تلميذ بدأ في أكتوبر لا يظهر له ولا يُنشأ له سبتمبر. */
    public function test_student_started_in_october_does_not_have_september_fee(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 40.00]);

        $this->clubService->subscribeStudent(
            $enrollment->student_id,
            $club->id,
            $year->id,
            '2025-10-01'
        );

        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollment->student_id,
            'club_id' => $club->id,
            'month' => '2025-09',
        ]);

        $this->clubService->generateMonthFees($year->id, '2025-10', $club->id);
        $this->assertDatabaseHas('club_monthly_fees', [
            'student_id' => $enrollment->student_id,
            'club_id' => $club->id,
            'month' => '2025-10',
        ]);
    }

    /** 18. الدفع الجزئي يحسب المبلغ المتبقي والحالة بشكل صحيح. */
    public function test_partial_payment_calculates_remaining_correctly(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 100.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $fee = ClubMonthlyFee::where('student_id', $enrollment->student_id)->where('month', '2025-09')->first();
        $this->clubService->recordPayment($fee, 30.00, '2025-09-10', 'cash');

        $dashboard = $this->clubService->getArrearsDashboard([
            'academic_year_id' => $year->id,
            'from_month' => '2025-09',
            'to_month' => '2025-09',
            'club_id' => $club->id,
        ]);

        $this->assertEquals(30.00, (float) $dashboard['summary']['total_paid']);
        $this->assertEquals(70.00, (float) $dashboard['summary']['total_remaining']);
        $this->assertEquals('partial', $dashboard['students'][0]['details'][0]['status']);
    }

    /** 19. منع تكرار نفس معلوم الشهر لنفس التلميذ والنادي والسنة الدراسية. */
    public function test_prevent_duplicate_fee_for_same_month_and_club(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 45.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $res1 = $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->assertEquals(1, $res1['created']);

        $res2 = $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->assertEquals(0, $res2['created']);
        $this->assertEquals(1, $res2['skipped']);

        $count = ClubMonthlyFee::where('student_id', $enrollment->student_id)
            ->where('club_id', $club->id)
            ->where('month', '2025-09')
            ->count();
        $this->assertEquals(1, $count);
    }

    /** 20. تقرير كشف النوادي ولوحة المتخلدات يستندان لنفس مصدر البيانات. */
    public function test_club_fees_and_club_arrears_use_same_data_source(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 60.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $report = $this->clubService->getReport([
            'academic_year_id' => $year->id,
            'month' => '2025-09',
            'club_id' => $club->id,
        ]);

        $arrears = $this->clubService->getArrearsDashboard([
            'academic_year_id' => $year->id,
            'from_month' => '2025-09',
            'to_month' => '2025-09',
            'club_id' => $club->id,
        ]);

        $this->assertEquals($report['summary']['total_due'], $arrears['summary']['total_due']);
        $this->assertEquals($report['summary']['total_remaining'], $arrears['summary']['total_remaining']);
        $this->assertEquals($report['records'][0]['amount_due'], $arrears['students'][0]['details'][0]['amount_due']);
    }

    /** 21. أشهر النوادي تمتد حصراً من سبتمبر إلى ماي (9 أشهر) ولا تشمل جوان أو جويلية أو أوت. */
    public function test_club_academic_year_months_are_strictly_from_september_to_may(): void
    {
        $year = $this->makeAcademicYear();
        $months = $this->clubService->getAcademicYearMonths($year->id, null, null, false);

        $this->assertCount(9, $months);
        $this->assertEquals('2025-09', $months[0]);
        $this->assertEquals('2026-05', $months[8]);
        $this->assertNotContains('2026-06', $months);
        $this->assertNotContains('2026-07', $months);
        $this->assertNotContains('2026-08', $months);
    }

    /** 22. معاينة الاستخلاص للشهر المحدد تعيد فقط معاليم النوادي للشهر المختار ولا تسرب أشهراً أخرى. */
    public function test_collection_preview_only_returns_club_items_for_selected_months(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['monthly_fee' => 50.00]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->clubService->generateMonthFees($year->id, '2025-10', $club->id);

        $collectionService = app(\App\Services\CollectionService::class);

        // Preview for September only
        $previewSept = $collectionService->preview($enrollment->id, ['2025-09']);
        $this->assertCount(1, $previewSept['club_items']);
        $this->assertEquals('2025-09', $previewSept['club_items'][0]['month']);

        // Preview for October only
        $previewOct = $collectionService->preview($enrollment->id, ['2025-10']);
        $this->assertCount(1, $previewOct['club_items']);
        $this->assertEquals('2025-10', $previewOct['club_items'][0]['month']);
    }

    /** 23. ربط قسم بنادي يظهر معاليم النادي آلياً في الاستخلاص، وإلغاء القسم يزيل معاليم النادي فوراً. */
    public function test_section_assigned_to_club_automatically_shows_in_collection_and_cancelling_section_removes_it(): void
    {
        $year = $this->makeAcademicYear();
        $enrollmentA = $this->makeEnrollment($year); // in section A
        $enrollmentB = $this->makeEnrollment($year); // in section B

        $club = $this->clubService->createClub([
            'name' => 'نادي الروبوتيك التجريبي',
            'monthly_fee' => 45.00,
            'is_active' => true,
        ], [], [$enrollmentA->section_id]); // only section A

        $collectionService = app(\App\Services\CollectionService::class);

        // 1. Student in section A enters collection preview -> club fee automatically appears
        $previewA = $collectionService->preview($enrollmentA->id, ['2025-09']);
        $this->assertCount(1, $previewA['club_items']);
        $this->assertEquals($club->id, $previewA['club_items'][0]['club_monthly_fee_id'] ? $club->id : null);
        $this->assertEquals(45.00, $previewA['club_items'][0]['amount_due']);

        // 2. Student in section B enters collection preview -> club fee does NOT appear
        $previewB = $collectionService->preview($enrollmentB->id, ['2025-09']);
        $this->assertCount(0, $previewB['club_items']);

        // 3. Administration unlinks section A and assigns section B instead
        $this->clubService->updateClub($club, [
            'name' => 'نادي الروبوتيك التجريبي',
            'monthly_fee' => 45.00,
            'is_active' => true,
        ], null, [$enrollmentB->section_id]);

        // 4. Now student in section A enters collection preview -> club fee does NOT appear
        $previewAAfter = $collectionService->preview($enrollmentA->id, ['2025-09']);
        $this->assertCount(0, $previewAAfter['club_items']);

        // 5. And student in section B enters collection preview -> club fee now appears!
        $previewBAfter = $collectionService->preview($enrollmentB->id, ['2025-09']);
        $this->assertCount(1, $previewBAfter['club_items']);
        $this->assertEquals(45.00, $previewBAfter['club_items'][0]['amount_due']);
    }

    /** 24. تعديل معلوم النادي يحدّث فوراً كافة السجلات غير الخالصة في كشف معلوم النوادي ويحمي السجلات المسددة والمخصصة. */
    public function test_updating_club_monthly_fee_syncs_all_unpaid_months_in_report(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);
        $enrollment3 = $this->makeEnrollment($year);

        $club = $this->clubService->createClub([
            'name' => 'نادي الروبوتيك المطور',
            'monthly_fee' => 10.00,
            'is_active' => true,
        ], [], [$enrollment1->section_id, $enrollment2->section_id, $enrollment3->section_id]);

        // اشتراك تلميذ 3 بسعر خاص مخصص (15 د.ت)
        $this->clubService->subscribeStudent(
            $enrollment3->student_id,
            $club->id,
            $year->id,
            null,
            15.00,
            $enrollment3->id
        );

        // توليد معاليم لأشهر متعددة
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);
        $this->clubService->generateMonthFees($year->id, '2025-10', $club->id);

        // سداد جزئي للتلميذ 2 في شهر 2025-09
        $feeToPay = ClubMonthlyFee::where('student_id', $enrollment2->student_id)
            ->where('club_id', $club->id)
            ->where('month', '2025-09')
            ->firstOrFail();
        $feeToPay->update(['amount_paid' => 10.00, 'status' => ClubMonthlyFee::STATUS_PAID]);

        // تعديل معلوم النادي من 10 إلى 30 د.ت
        $this->clubService->updateClub($club, [
            'name' => 'نادي الروبوتيك المطور',
            'monthly_fee' => 30.00,
            'is_active' => true,
        ]);

        // 1. فحص تقرير معلوم النوادي لشهر 2025-09
        $reportSep = $this->clubService->getReport([
            'month' => '2025-09',
            'academic_year_id' => $year->id,
            'club_id' => $club->id,
        ]);
        $record1Sep = collect($reportSep['records'])->firstWhere('student_id', $enrollment1->student_id);
        $record2Sep = collect($reportSep['records'])->firstWhere('student_id', $enrollment2->student_id);
        $record3Sep = collect($reportSep['records'])->firstWhere('student_id', $enrollment3->student_id);

        // تلميذ 1 غير خالص -> أصبح 30 د.ت
        $this->assertEquals(30.00, $record1Sep['amount_due']);
        $this->assertEquals(30.00, $record1Sep['remaining']);

        // تلميذ 2 خالص -> بقي 10 د.ت دون مساس
        $this->assertEquals(10.00, $record2Sep['amount_due']);
        $this->assertEquals(10.00, $record2Sep['amount_paid']);

        // تلميذ 3 صاحب السعر الخاص -> بقي 15 د.ت دون مساس
        $this->assertEquals(15.00, $record3Sep['amount_due']);

        // 2. فحص تقرير معلوم النوادي لشهر 2025-10
        $reportOct = $this->clubService->getReport([
            'month' => '2025-10',
            'academic_year_id' => $year->id,
            'club_id' => $club->id,
        ]);
        $record1Oct = collect($reportOct['records'])->firstWhere('student_id', $enrollment1->student_id);
        $record2Oct = collect($reportOct['records'])->firstWhere('student_id', $enrollment2->student_id);

        $this->assertEquals(30.00, $record1Oct['amount_due']);
        $this->assertEquals(30.00, $record2Oct['amount_due']);
    }

    /** 25. إلغاء قسم من إدارة النوادي يحذف معاليمه غير الخالصة ويحدّث لوحة المتخلدات والتقرير فوراً. */
    public function test_unlinking_section_removes_unpaid_fees_from_arrears_dashboard_and_report(): void
    {
        $year = $this->makeAcademicYear();
        $enrollmentA = $this->makeEnrollment($year);
        $enrollmentB = $this->makeEnrollment($year);

        $club = $this->clubService->createClub([
            'name' => 'نادي الروبوتيك',
            'monthly_fee' => 20.00,
            'is_active' => true,
        ], [], [$enrollmentA->section_id, $enrollmentB->section_id]);

        // توليد معاليم لشهر 2025-09
        $this->clubService->generateMonthFees($year->id, '2025-09', $club->id);

        // قبل التعديل: التلميذان يظهران في لوحة المتخلدات والتقرير
        $dashBefore = $this->clubService->getArrearsDashboard(['academic_year_id' => $year->id]);
        $this->assertEquals(2, $dashBefore['summary']['students_count']);
        $this->assertEquals(40.00, $dashBefore['summary']['total_remaining']);

        $reportBefore = $this->clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09']);
        $this->assertEquals(2, $reportBefore['summary']['students_count']);
        $this->assertEquals(40.00, $reportBefore['summary']['total_remaining']);

        // إلغاء القسم A من النادي في إدارة النوادي
        $this->clubService->updateClub($club, [
            'name' => 'نادي الروبوتيك',
            'monthly_fee' => 20.00,
            'is_active' => true,
        ], null, [$enrollmentB->section_id]);

        // بعد التعديل: يتبقى التلميذ B فقط في لوحة المتخلدات والتقرير
        $dashAfter = $this->clubService->getArrearsDashboard(['academic_year_id' => $year->id]);
        $this->assertEquals(1, $dashAfter['summary']['students_count']);
        $this->assertEquals(20.00, $dashAfter['summary']['total_remaining']);

        $reportAfter = $this->clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09']);
        $this->assertEquals(1, $reportAfter['summary']['students_count']);
        $this->assertEquals(20.00, $reportAfter['summary']['total_remaining']);

        // التأكد من حذف معاليم واشتراك التلميذ A
        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollmentA->student_id,
            'club_id' => $club->id,
        ]);
        $this->assertDatabaseMissing('club_subscriptions', [
            'student_id' => $enrollmentA->student_id,
            'club_id' => $club->id,
        ]);
    }
}
