<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnpaidMonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_returns_only_students_without_real_monthly_payment(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $paidEnrollment = $this->makeEnrollment($year, $this->makeStudent('PAID-1', 'أحمد'));
        $unpaidEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('UNPAID-1', 'مريم'));
        $otherFeeEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('OTHER-1', 'سليم'));
        $cancelledEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('CANCELLED-1', 'ليلى'));
        $lateEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('LATE-1', 'يوسف'));
        $lateEnrollment->update(['enrollment_date' => '2025-10-01']);

        $this->makePayment($paidEnrollment, CashTransaction::CATEGORY_MONTHLY_FEE);
        $this->makePayment($otherFeeEnrollment, CashTransaction::CATEGORY_OTHER_INCOME);
        $this->makePayment($cancelledEnrollment, CashTransaction::CATEGORY_MONTHLY_FEE, true);

        $response = $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month' => '2025-09',
            'section_id' => $paidEnrollment->section_id,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.unpaid_students_count', 3)
            ->assertJsonMissing(['student_code' => 'PAID-1'])
            ->assertJsonFragment(['student_code' => 'UNPAID-1'])
            ->assertJsonFragment(['student_code' => 'OTHER-1'])
            ->assertJsonFragment(['student_code' => 'CANCELLED-1'])
            ->assertJsonMissing(['student_code' => 'LATE-1']);

        $this->assertSame(
            ['سليم اختبار', 'ليلى اختبار', 'مريم اختبار'],
            collect($response->json('rows'))->pluck('student_name')->all()
        );
        $this->assertSame($unpaidEnrollment->id, $response->json('rows.2.enrollment_id'));
    }

    public function test_options_return_year_months_and_sections(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);

        $this->getJson('/api/reports/unpaid-monthly/options?academic_year_id='.$year->id)
            ->assertOk()
            ->assertJsonPath('selected_year_id', $year->id)
            ->assertJsonPath('months.0.value', '2025-09')
            ->assertJsonPath('months.0.label', 'سبتمبر 2025')
            ->assertJsonPath('sections.0.id', $enrollment->section_id);
    }

    public function test_month_must_belong_to_selected_academic_year(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);

        $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month' => '2026-08',
            'section_id' => $enrollment->section_id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_print_report_hides_filters_buttons_and_inputs(): void
    {
        $page = file_get_contents(resource_path('js/pages/Income/UnpaidMonthlyReportPage.tsx'));

        $this->assertIsString($page);
        $this->assertStringContainsString('@media print', $page);
        $this->assertStringContainsString('.unpaid-monthly-report-page button', $page);
        $this->assertStringContainsString('.unpaid-monthly-report-page select', $page);
        $this->assertStringContainsString('.unpaid-monthly-report-page input', $page);
        $this->assertStringContainsString('display: none !important', $page);
    }

    private function makeReportViewer()
    {
        $user = $this->makeUser('report_viewer');
        $user->update(['is_active' => true]);
        $permission = Permission::create([
            'name' => 'view_reports',
            'display_name' => 'عرض التقارير',
            'group' => 'Finance',
        ]);
        $user->role->permissions()->attach($permission);

        return $user;
    }

    private function makeStudent(string $code, string $firstName): Student
    {
        return Student::create([
            'student_code' => $code,
            'first_name' => $firstName,
            'last_name' => 'اختبار',
            'gender' => 'male',
            'guardian_first_name' => 'ولي',
            'guardian_last_name' => $firstName,
            'guardian_phone' => '22000000',
            'status' => 'active',
        ]);
    }

    private function makeSameSectionEnrollment(Enrollment $reference, Student $student): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $reference->academic_year_id,
            'level_id' => $reference->level_id,
            'section_id' => $reference->section_id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
    }

    private function makePayment(Enrollment $enrollment, string $category, bool $cancelled = false): void
    {
        $payment = Payment::create([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'amount' => 100,
            'payment_date' => '2025-09-15',
            'method' => 'cash',
            'cancelled_at' => $cancelled ? now() : null,
        ]);

        CashTransaction::create([
            'transaction_date' => '2025-09-15',
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => $category,
            'amount' => 100,
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'academic_year_id' => $enrollment->academic_year_id,
            'cancelled_at' => $cancelled ? now() : null,
        ]);
    }
}
