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
            'name' => 'نادي تجريبي',
            'fee_category_id' => $category->id,
            'monthly_fee' => 50.00,
            'is_active' => true,
        ], $attributes));
    }

    /** 1. لا يتم إنشاء سجل دفع لتلميذ غير مسجل في النادي. */
    public function test_no_fee_record_created_for_unregistered_student(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);

        $club = $this->makeClub([
            'name' => 'نادي الشطرنج',
            'monthly_fee' => 50.00,
            'is_active' => true,
        ]);

        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);

        $this->clubService->generateMonthFees($year->id, '2026-05');

        $this->assertDatabaseHas('club_monthly_fees', [
            'student_id' => $enrollment1->student_id,
            'club_id' => $club->id,
            'month' => '2026-05',
        ]);

        $this->assertDatabaseMissing('club_monthly_fees', [
            'student_id' => $enrollment2->student_id,
            'club_id' => $club->id,
            'month' => '2026-05',
        ]);
    }

    /** 2. يتم إنشاء سجل واحد فقط للتلميذ المسجل في النادي للشهر المحدد. */
    public function test_exactly_one_record_created_for_enrolled_student_for_month(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الرسم', 'monthly_fee' => 40.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $result1 = $this->clubService->generateMonthFees($year->id, '2026-05');
        $this->assertEquals(1, $result1['created']);

        $count = ClubMonthlyFee::where('student_id', $enrollment->student_id)
            ->where('club_id', $club->id)
            ->where('month', '2026-05')
            ->count();

        $this->assertEquals(1, $count);
    }

    /** 3. التلميذ الذي دفع بالكامل يظهر كـ paid/مدفوع. */
    public function test_fully_paid_student_displays_as_paid(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الموسيقى', 'monthly_fee' => 60.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $feeRecord = ClubMonthlyFee::where('student_id', $enrollment->student_id)->first();

        $this->clubService->recordPayment(
            $feeRecord,
            60.00,
            '2026-05-10',
            'cash',
            null,
            'دفعة كاملة'
        );

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals(1, $report['summary']['paid_count']);
        $this->assertEquals('paid', $report['records'][0]['status']);
        $this->assertEquals('مدفوع بالكامل', $report['records'][0]['status_label']);
    }

    /** 4. التلميذ الذي لم يدفع يظهر كـ unpaid/غير مسدد. */
    public function test_unpaid_student_displays_as_unpaid(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي المسرح', 'monthly_fee' => 45.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);

        $this->assertEquals(1, $report['summary']['unpaid_count']);
        $this->assertEquals('unpaid', $report['records'][0]['status']);
        $this->assertEquals('غير مسدد', $report['records'][0]['status_label']);
    }

    /** 5. لا يمكن إنشاء سجل مكرر لنفس الطالب والنادي والشهر والسنة. */
    public function test_duplicate_record_prevention_for_same_student_club_month_year(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الروبوتيك', 'monthly_fee' => 80.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $res1 = $this->clubService->generateMonthFees($year->id, '2026-05');
        $res2 = $this->clubService->generateMonthFees($year->id, '2026-05');

        $this->assertEquals(1, $res1['created']);
        $this->assertEquals(0, $res2['created']);
        $this->assertEquals(1, $res2['skipped']);

        $this->assertEquals(1, ClubMonthlyFee::where('month', '2026-05')->count());
    }

    /** 6. تغيير معلوم النادي لا يعدّل الأشهر القديمة. */
    public function test_updating_club_fee_does_not_modify_past_month_records(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي السباحة', 'monthly_fee' => 100.00, 'is_active' => true]);

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

    /** 7. فلترة التقرير حسب القسم تعيد التلاميذ من القسم المحدد فقط. */
    public function test_filtering_report_by_section_returns_only_students_from_that_section(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);

        $club = $this->makeClub(['name' => 'نادي اللغات', 'monthly_fee' => 50.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);
        $this->clubService->subscribeStudent($enrollment2->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $reportSection1 = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'section_id' => $enrollment1->section_id,
        ]);

        $this->assertEquals(1, count($reportSection1['records']));
        $this->assertEquals($enrollment1->student_id, $reportSection1['records'][0]['student_id']);
    }

    /** 8. التلميذ غير المسجل لا يظهر ضمن قائمة غير المسددين. */
    public function test_unregistered_student_does_not_appear_in_unpaid_list(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment1 = $this->makeEnrollment($year);
        $enrollment2 = $this->makeEnrollment($year);

        $club = $this->makeClub(['name' => 'نادي الرياضيات', 'monthly_fee' => 30.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment1->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $report = $this->clubService->getReport([
            'month' => '2026-05',
            'academic_year_id' => $year->id,
            'status' => 'unpaid',
        ]);

        $studentIdsInUnpaid = collect($report['records'])->pluck('student_id')->all();

        $this->assertContains($enrollment1->student_id, $studentIdsInUnpaid);
        $this->assertNotContains($enrollment2->student_id, $studentIdsInUnpaid);
    }

    /** 9. المستخدم غير المخول لا يستطيع تعديل أو تسجيل الدفع. */
    public function test_unauthorized_user_cannot_modify_or_record_payment(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الفنون', 'monthly_fee' => 40.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);
        $this->clubService->generateMonthFees($year->id, '2026-05');

        $feeRecord = ClubMonthlyFee::where('student_id', $enrollment->student_id)->first();

        $unauthorizedUser = $this->makeUserWithPermissions('guest_user', []);
        Sanctum::actingAs($unauthorizedUser);

        $payload = [
            'amount_paid' => 40.00,
            'paid_at' => '2026-05-10',
            'method' => 'cash',
        ];

        $this->postJson("/api/club-monthly-fees/{$feeRecord->id}/collect", $payload)
            ->assertStatus(403);
    }

    /** 10. تقرير الشهر السابق يبقى محفوظاً عند إنشاء شهر جديد. */
    public function test_previous_month_report_preserved_when_generating_new_month(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub(['name' => 'نادي الإعلامية', 'monthly_fee' => 70.00, 'is_active' => true]);

        $this->clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id);

        $this->clubService->generateMonthFees($year->id, '2026-05');
        $mayFee = ClubMonthlyFee::where('month', '2026-05')->first();
        $this->clubService->recordPayment($mayFee, 70.00, '2026-05-10', 'cash');

        $this->clubService->generateMonthFees($year->id, '2026-06');

        $mayReport = $this->clubService->getReport(['month' => '2026-05', 'academic_year_id' => $year->id]);
        $this->assertEquals('paid', $mayReport['records'][0]['status']);
        $this->assertEquals(70.00, $mayReport['summary']['total_paid']);

        $juneReport = $this->clubService->getReport(['month' => '2026-06', 'academic_year_id' => $year->id]);
        $this->assertEquals('unpaid', $juneReport['records'][0]['status']);
        $this->assertEquals(0.00, $juneReport['summary']['total_paid']);
    }
}
