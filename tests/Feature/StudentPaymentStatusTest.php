<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paid_unpaid_and_waived_rows(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $paidEnrollment = $this->makeEnrollment($year, $this->makeStudent('PAID-1', 'أحمد'));
        $unpaidEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('UNPAID-1', 'مريم'));
        $waivedEnrollment = $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('WAIVED-1', 'سليم'));

        $this->makePayment($paidEnrollment, CashTransaction::CATEGORY_MONTHLY_FEE);
        MonthlyDiscount::create([
            'enrollment_id' => $waivedEnrollment->id,
            'academic_year_id' => $year->id,
            'discount_type' => MonthlyDiscount::TYPE_FULL_WAIVER,
            'start_month' => '2025-09',
            'end_month' => '2026-06',
            'reason' => 'اختبار',
        ]);

        $params = http_build_query([
            'academic_year_id' => $year->id,
            'month' => '2025-09',
            'section_id' => $paidEnrollment->section_id,
        ]);

        $this->getJson('/api/reports/payment-status?'.$params)
            ->assertOk()
            ->assertJsonPath('summary.paid_students_count', 1)
            ->assertJsonPath('summary.unpaid_students_count', 1)
            ->assertJsonPath('summary.waived_students_count', 1)
            ->assertJsonPath('summary.total_students_count', 3)
            ->assertJsonFragment(['student_code' => 'PAID-1', 'status' => 'paid'])
            ->assertJsonFragment(['student_code' => 'UNPAID-1', 'status' => 'unpaid'])
            ->assertJsonFragment(['student_code' => 'WAIVED-1', 'status' => 'waived']);
    }

    public function test_status_filter_limits_rows(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $paidEnrollment = $this->makeEnrollment($year, $this->makeStudent('PAID-2', 'أحمد'));
        $this->makeSameSectionEnrollment($paidEnrollment, $this->makeStudent('UNPAID-2', 'مريم'));
        $this->makePayment($paidEnrollment, CashTransaction::CATEGORY_MONTHLY_FEE);

        $base = [
            'academic_year_id' => $year->id,
            'month' => '2025-09',
            'section_id' => $paidEnrollment->section_id,
        ];

        $this->getJson('/api/reports/payment-status?'.http_build_query($base + ['status' => 'paid']))
            ->assertOk()
            ->assertJsonPath('summary.total_students_count', 1)
            ->assertJsonFragment(['student_code' => 'PAID-2']);

        $this->getJson('/api/reports/payment-status?'.http_build_query($base + ['status' => 'unpaid']))
            ->assertOk()
            ->assertJsonPath('summary.total_students_count', 1)
            ->assertJsonFragment(['student_code' => 'UNPAID-2']);
    }

    public function test_options_return_year_months_and_sections(): void
    {
        Sanctum::actingAs($this->makeReportViewer());

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);

        $this->getJson('/api/reports/payment-status/options?academic_year_id='.$year->id)
            ->assertOk()
            ->assertJsonPath('selected_year_id', $year->id)
            ->assertJsonPath('months.0.value', '2025-09')
            ->assertJsonPath('sections.0.id', $enrollment->section_id);
    }

    public function test_print_report_hides_filters_buttons_and_inputs(): void
    {
        $page = file_get_contents(resource_path('js/pages/Income/StudentPaymentStatusPage.tsx'));

        $this->assertIsString($page);
        $this->assertStringContainsString('@media print', $page);
        $this->assertStringContainsString('.payment-status-page button', $page);
        $this->assertStringContainsString('.payment-status-page select', $page);
        $this->assertStringContainsString('.payment-status-page input', $page);
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
