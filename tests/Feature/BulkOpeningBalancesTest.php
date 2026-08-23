<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkOpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        $u = $this->makeUser('admin');
        $u->update(['is_active' => true]);
        return $u;
    }

    private function setupYearAndSection(): array
    {
        $year = $this->makeAcademicYear('2025-2026');
        $level = Level::create(['name' => 'السنة الأولى', 'code' => 'L1-'.uniqid(), 'order' => 1]);
        $section = Section::create(['level_id' => $level->id, 'name' => 'أ', 'code' => 'S1-'.uniqid(), 'capacity' => 30]);
        return [$year, $level, $section];
    }

    private function makeStudentInSection(AcademicYear $year, Section $section, Level $level): Student
    {
        $student = Student::create([
            'student_code' => 'STU-'.uniqid(),
            'first_name' => 'تلميذ',
            'last_name' => uniqid(),
            'gender' => 'male',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'status' => 'active',
            'enrollment_date' => $year->start_date,
        ]);
        return $student;
    }

    private function makeEmployee(string $type = 'worker'): Employee
    {
        return Employee::create([
            'first_name' => 'موظف',
            'last_name' => uniqid(),
            'staff_type' => $type,
            'is_active' => true,
        ]);
    }

    public function test_bulk_options_requires_treasury_permission(): void
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/manual-debts/bulk-options')->assertForbidden();
    }

    public function test_bulk_options_returns_required_data(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $this->makeEmployee('worker');

        $res = $this->getJson('/api/manual-debts/bulk-options')->assertOk();
        $res->assertJsonStructure(['active_year' => ['id','name','start_date'], 'levels','sections','employees','existing_liabilities']);
        $this->assertEquals($year->id, $res->json('active_year.id'));
    }

    public function test_section_students_returns_students_and_existing_debts(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $oldYear = AcademicYear::create(['name' => '2023-2024', 'start_date' => '2023-09-01', 'end_date' => '2024-06-30', 'is_active' => false]);
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $this->makeEnrollment($oldYear, $s1);
        $s2 = $this->makeStudentInSection($year, $section, $level);
        // create debt for s1
        $this->postJson('/api/manual-debts', [
            'student_id' => $s1->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين قديم',
            'original_amount' => 500,
        ])->assertCreated();

        $res = $this->getJson('/api/manual-debts/section-students?section_id='.$section->id)->assertOk();
        $students = $res->json('students');
        $this->assertCount(2, $students);
        $first = collect($students)->firstWhere('id', $s1->id);
        $second = collect($students)->firstWhere('id', $s2->id);
        $this->assertNotNull($first['existing']);
        $this->assertEquals(500, $first['existing']['original_amount']);
        $this->assertNull($second['existing']);
    }

    public function test_student_bulk_creates_and_skips_zero_amount(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $s2 = $this->makeStudentInSection($year, $section, $level);
        $s3 = $this->makeStudentInSection($year, $section, $level);

        $res = $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['student_id' => $s1->id, 'debt_type' => 'tuition', 'amount' => 300],
                ['student_id' => $s2->id, 'debt_type' => 'other', 'amount' => 0],
                ['student_id' => $s3->id, 'debt_type' => 'club', 'amount' => 200, 'notes' => 'ملاحظة'],
            ],
        ])->assertCreated();
        $this->assertEquals(2, $res->json('created'));
        $this->assertEquals(0, $res->json('updated'));
        $this->assertDatabaseCount('manual_student_debts', 2);
        $this->assertDatabaseMissing('manual_student_debts', ['student_id' => $s2->id]);
        // لا ينشئ cash_transaction
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_student_bulk_updates_existing_without_duplicate(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $oldYear = AcademicYear::create(['name' => '2023-2024', 'start_date' => '2023-09-01', 'end_date' => '2024-06-30', 'is_active' => false]);
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $this->makeEnrollment($oldYear, $s1);
        $this->postJson('/api/manual-debts', [
            'student_id' => $s1->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين',
            'original_amount' => 400,
        ])->assertCreated();

        $res = $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['student_id' => $s1->id, 'debt_type' => 'other', 'amount' => 700],
            ],
        ])->assertCreated();
        $this->assertEquals(0, $res->json('created'));
        $this->assertEquals(1, $res->json('updated'));
        $this->assertDatabaseCount('manual_student_debts', 1);
        $this->assertDatabaseHas('manual_student_debts', ['student_id' => $s1->id, 'original_amount' => 700, 'debt_type' => 'other']);
    }

    public function test_student_bulk_prevents_update_of_partially_collected_debt(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        // need old year for bridge logic
        $oldYear = AcademicYear::create(['name'=>'2024-2025','start_date'=>'2024-09-01','end_date'=>'2025-06-30','is_active'=>false]);
        $s1 = $this->makeStudentInSection($year, $section, $level);
        // create old enrollment for bridge requirement
        $this->makeEnrollment($oldYear, $s1);
        $this->postJson('/api/manual-debts', [
            'student_id' => $s1->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين',
            'original_amount' => 1000,
        ])->assertCreated();
        $debt = ManualStudentDebt::where('student_id', $s1->id)->first();
        // partial collection
        $enrollment = Enrollment::where('student_id',$s1->id)->where('academic_year_id',$year->id)->first();
        $this->postJson('/api/payments/collect', [
            'student_id' => $s1->id,
            'enrollment_id' => $enrollment->id,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 200]],
        ])->assertCreated();

        $response = $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['student_id' => $s1->id, 'debt_type' => 'other', 'amount' => 900],
            ],
        ]);
        $response->assertStatus(422);
        $this->assertTrue(str_contains($response->json('message') ?? '', 'حُصّل منه'));
        $this->assertDatabaseHas('manual_student_debts', ['id' => $debt->id, 'original_amount' => 1000]);
    }

    public function test_student_bulk_rollback_when_student_not_enrolled(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $s2 = Student::create(['student_code'=>'STU-'.uniqid(),'first_name'=>'خارج','last_name'=>'القسم','gender'=>'male','status'=>'active']);
        // s2 not enrolled in section/year

        $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['student_id' => $s1->id, 'debt_type' => 'tuition', 'amount' => 300],
                ['student_id' => $s2->id, 'debt_type' => 'tuition', 'amount' => 300],
            ],
        ])->assertStatus(422);
        $this->assertDatabaseCount('manual_student_debts', 0);
    }

    public function test_student_bulk_rejects_original_year_equals_current(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => $year->name,
            'items' => [['student_id'=>$s1->id,'debt_type'=>'tuition','amount'=>100]],
        ])->assertStatus(422);
    }

    public function test_student_bulk_no_duplicate_active_debt(): void
    {
        Sanctum::actingAs($this->admin());
        [$year, $level, $section] = $this->setupYearAndSection();
        $s1 = $this->makeStudentInSection($year, $section, $level);
        $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['student_id'=>$s1->id,'debt_type'=>'tuition','amount'=>300]],
        ])->assertCreated();
        $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['student_id'=>$s1->id,'debt_type'=>'tuition','amount'=>400]],
        ])->assertCreated();
        $this->assertDatabaseCount('manual_student_debts', 1);
    }

    public function test_employee_bulk_creates_with_staff_type_validation(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $w = $this->makeEmployee('worker');
        $t = $this->makeEmployee('monthly_teacher');
        // worker with advance should fail whole batch rollback
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['employee_id'=>$w->id,'liability_type'=>'advance','amount'=>300]],
        ])->assertStatus(422);
        $this->assertDatabaseCount('employee_liabilities', 0);

        // correct
        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[
                ['employee_id'=>$w->id,'liability_type'=>'debt','amount'=>300],
                ['employee_id'=>$t->id,'liability_type'=>'advance','amount'=>500],
            ],
        ])->assertCreated();
        $this->assertEquals(2, $res->json('created'));
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_employee_bulk_zero_amount_skipped_and_update_blocked_when_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $e1 = $this->makeEmployee('worker');
        $e2 = $this->makeEmployee('worker');
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[
                ['employee_id'=>$e1->id,'liability_type'=>'debt','amount'=>0],
                ['employee_id'=>$e2->id,'liability_type'=>'debt','amount'=>400],
            ],
        ])->assertCreated();
        $this->assertDatabaseCount('employee_liabilities', 1);
        $this->assertDatabaseHas('employee_liabilities',['employee_id'=>$e2->id]);

        // create liability then simulate paid via direct cash transaction (since no pay route for new collection type yet, but paid() reads old collection; we seed directly)
        // Instead test update allowed when not paid
        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['employee_id'=>$e2->id,'liability_type'=>'debt','amount'=>600]],
        ])->assertCreated();
        $this->assertEquals(1, $res->json('updated'));
        $this->assertDatabaseHas('employee_liabilities',['employee_id'=>$e2->id,'original_amount'=>600]);

        // now make it paid partially via direct ledger entry
        $liab = EmployeeLiability::where('employee_id',$e2->id)->first();
        CashTransaction::create([
            'transaction_date'=>now()->toDateString(),
            'direction'=>CashTransaction::DIRECTION_IN,
            'category'=>CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'amount'=>100,
            'source_type'=>$liab->getMorphClass(),
            'source_id'=>$liab->getKey(),
            'academic_year_id'=>$year->id,
        ]);
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['employee_id'=>$e2->id,'liability_type'=>'debt','amount'=>800]],
        ])->assertStatus(422);
    }

    public function test_bulk_does_not_use_old_liability_payment(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $e = $this->makeEmployee('worker');
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id'=>$year->id,'original_year_label'=>'2024/2025',
            'items'=>[['employee_id'=>$e->id,'liability_type'=>'debt','amount'=>300]],
        ])->assertCreated();
        $this->assertDatabaseMissing('cash_transactions',['category'=>CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT]);
        $this->assertDatabaseMissing('cash_transactions',['category'=>CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION]);
    }
}
