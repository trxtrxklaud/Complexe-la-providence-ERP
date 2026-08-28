<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairYearFeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AcademicYear $year2025;
    private AcademicYear $year2026;
    private Level $levelL1;
    private Section $sectionA;
    private FeeCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = \App\Models\Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'مدير النظام']
        );

        $this->admin = User::create([
            'email' => 'admin@test.com',
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'username' => 'admintest',
            'password' => bcrypt('password123'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->year2025 = AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);

        $this->year2026 = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date' => '2027-06-30',
            'is_active' => false,
        ]);

        $levelsToCreate = [
            ['code' => 'L1', 'name' => 'السنة الأولى', 'order' => 1],
            ['code' => 'L2', 'name' => 'السنة الثانية', 'order' => 2],
            ['code' => 'L3', 'name' => 'السنة الثالثة', 'order' => 3],
            ['code' => 'L4', 'name' => 'السنة الرابعة', 'order' => 4],
            ['code' => 'L5', 'name' => 'السنة الخامسة', 'order' => 5],
            ['code' => 'L6', 'name' => 'السنة السادسة', 'order' => 6],
            ['code' => 'PRE1', 'name' => 'روضة', 'order' => 7],
            ['code' => 'PRE2', 'name' => 'تمهيدي', 'order' => 8],
            ['code' => 'PRE3', 'name' => 'تحضيري', 'order' => 9],
        ];
        foreach ($levelsToCreate as $lvl) {
            $created = Level::firstOrCreate(['code' => $lvl['code']], $lvl);
            if ($lvl['code'] === 'L1') {
                $this->levelL1 = $created;
            }
        }

        $this->sectionA = Section::create([
            'level_id' => $this->levelL1->id,
            'name' => 'أ',
            'code' => 'L1-أ',
            'capacity' => 30,
        ]);

        $this->category = FeeCategory::create([
            'name' => 'الرسوم الدراسية',
            'code' => 'scolarite',
            'is_recurring' => true,
        ]);
    }

    public function test_collection_preview_flags_missing_fee_plan_instead_of_full_waiver(): void
    {
        $student = Student::create([
            'student_code' => 'PRV-TEST-001',
            'first_name' => 'أحمد',
            'last_name' => 'التونسي',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year2026->id,
            'level_id' => $this->levelL1->id,
            'section_id' => $this->sectionA->id,
            'status' => 'active',
            'enrollment_date' => '2026-09-15',
        ]);

        // FeePlan is NOT created for 2026-2027
        $service = app(CollectionService::class);
        $preview = $service->preview($enrollment->id, ['2026-09']);

        $this->assertTrue($preview['fee_plan_missing']);
        $this->assertFalse($preview['is_fully_waived']);
        $this->assertFalse($preview['can_collect']);
        $this->assertNotNull($preview['fee_plan_missing_message']);
        $this->assertEquals(0.0, $preview['gross_amount']);
    }

    public function test_collection_preview_returns_correct_amounts_when_fee_plan_exists(): void
    {
        FeePlan::create([
            'academic_year_id' => $this->year2026->id,
            'level_id' => $this->levelL1->id,
            'fee_category_id' => $this->category->id,
            'name' => 'القسط الشهري — الأولى',
            'amount' => 150.00,
            'frequency' => 'monthly',
            'due_day' => 1,
        ]);

        $student = Student::create([
            'student_code' => 'PRV-TEST-002',
            'first_name' => 'سارة',
            'last_name' => 'المنستيري',
            'gender' => 'female',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year2026->id,
            'level_id' => $this->levelL1->id,
            'section_id' => $this->sectionA->id,
            'status' => 'active',
            'enrollment_date' => '2026-09-15',
        ]);

        $service = app(CollectionService::class);
        $preview = $service->preview($enrollment->id, ['2026-09']);

        $this->assertFalse($preview['fee_plan_missing']);
        $this->assertFalse($preview['is_fully_waived']);
        $this->assertTrue($preview['can_collect']);
        $this->assertEquals(150.00, $preview['gross_amount']);
        $this->assertEquals(150.00, $preview['net_due']);
        $this->assertEquals(150.00, $preview['remaining_amount']);
    }

    public function test_repair_year_fees_command_dry_run_does_not_persist_changes(): void
    {
        $initialPlansCount = FeePlan::count();
        $this->assertTrue($this->year2025->fresh()->is_active);
        $this->assertFalse($this->year2026->fresh()->is_active);

        $this->artisan('legacy:repair-year-fees', [
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertEquals($initialPlansCount, FeePlan::count());
        $this->assertTrue($this->year2025->fresh()->is_active);
        $this->assertFalse($this->year2026->fresh()->is_active);
    }

    public function test_repair_year_fees_command_executes_safely_and_activates_2026(): void
    {
        $student = Student::create([
            'student_code' => 'PRV-TEST-003',
            'first_name' => 'يوسف',
            'last_name' => 'الحربي',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year2026->id,
            'level_id' => $this->levelL1->id,
            'section_id' => $this->sectionA->id,
            'status' => 'active',
            'enrollment_date' => '2026-09-15',
        ]);

        $debt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year2026->id,
            'debt_type' => 'tuition',
            'original_amount' => 250.00,
            'original_year_label' => '2025-2026',
            'description' => 'دين سابق',
            'status' => 'pending',
            'created_by' => 1,
        ]);

        // Run the command
        $this->artisan('legacy:repair-year-fees')->assertSuccessful();

        // Verify active academic year
        $this->assertFalse($this->year2025->fresh()->is_active);
        $this->assertTrue($this->year2026->fresh()->is_active);

        // Verify 2026-2027 fee plans imported
        $plans2026 = FeePlan::where('academic_year_id', $this->year2026->id)->get();
        $this->assertCount(9, $plans2026);

        // Verify L1 fee plan amount is 150.00
        $l1Plan = FeePlan::where('academic_year_id', $this->year2026->id)
            ->where('level_id', $this->levelL1->id)
            ->first();
        $this->assertNotNull($l1Plan);
        $this->assertEquals(150.00, (float) $l1Plan->amount);

        // Verify L6 fee plan amount is 190.00
        $l6Level = Level::where('code', 'L6')->first();
        $l6Plan = FeePlan::where('academic_year_id', $this->year2026->id)
            ->where('level_id', $l6Level->id)
            ->first();
        $this->assertNotNull($l6Plan);
        $this->assertEquals(190.00, (float) $l6Plan->amount);

        // Verify students, enrollments, manual debts remain intact and unchanged
        $this->assertDatabaseHas('students', ['id' => $student->id, 'student_code' => 'PRV-TEST-003']);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'academic_year_id' => $this->year2026->id]);
        $this->assertDatabaseHas('manual_student_debts', ['id' => $debt->id, 'original_amount' => 250.00]);

        // Running a second time is idempotent (no duplicates created)
        $this->artisan('legacy:repair-year-fees')->assertSuccessful();
        $this->assertEquals(9, FeePlan::where('academic_year_id', $this->year2026->id)->count());
    }
}
