<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_section_roster_returns_only_active_students_for_the_selected_year(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$source] = $this->makeSections();
        $included = $this->makeStudent('SRC-001', 'أحمد');
        $excluded = $this->makeStudent('SRC-002', 'مريم');

        $this->enroll($included, $year, $source, 'active');
        $this->enroll($excluded, $year, $source, 'withdrawn');

        $this->getJson('/api/students/transfer-roster?'.http_build_query([
            'academic_year_id' => $year->id,
            'section_id' => $source->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $included->id);
    }

    public function test_only_selected_students_are_transferred_and_the_enrollment_is_preserved(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$source, $destination] = $this->makeSections();
        $selected = $this->makeStudent('MOVE-001', 'سليم');
        $notSelected = $this->makeStudent('MOVE-002', 'سارة');
        $selectedEnrollment = $this->enroll($selected, $year, $source);
        $otherEnrollment = $this->enroll($notSelected, $year, $source);

        $this->postJson('/api/students/transfer', [
            'academic_year_id' => $year->id,
            'source_section_id' => $source->id,
            'destination_section_id' => $destination->id,
            'student_ids' => [$selected->id],
        ])
            ->assertOk()
            ->assertJsonPath('transferred', 1);

        $this->assertDatabaseHas('enrollments', [
            'id' => $selectedEnrollment->id,
            'student_id' => $selected->id,
            'level_id' => $destination->level_id,
            'section_id' => $destination->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $otherEnrollment->id,
            'student_id' => $notSelected->id,
            'section_id' => $source->id,
            'status' => 'active',
        ]);
    }

    public function test_transfer_is_blocked_when_no_student_is_selected(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$source, $destination] = $this->makeSections();

        $this->postJson('/api/students/transfer', [
            'academic_year_id' => $year->id,
            'source_section_id' => $source->id,
            'destination_section_id' => $destination->id,
            'student_ids' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_ids');
    }

    public function test_transfer_is_atomic_when_a_selected_student_is_not_in_the_source_section(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$source, $destination, $otherSection] = $this->makeSections();
        $validStudent = $this->makeStudent('ATOMIC-001', 'ليلى');
        $invalidStudent = $this->makeStudent('ATOMIC-002', 'منير');
        $validEnrollment = $this->enroll($validStudent, $year, $source);
        $this->enroll($invalidStudent, $year, $otherSection);

        $this->postJson('/api/students/transfer', [
            'academic_year_id' => $year->id,
            'source_section_id' => $source->id,
            'destination_section_id' => $destination->id,
            'student_ids' => [$validStudent->id, $invalidStudent->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_ids');

        $this->assertDatabaseHas('enrollments', [
            'id' => $validEnrollment->id,
            'section_id' => $source->id,
        ]);
    }

    private function makeSections(): array
    {
        $firstLevel = Level::create(['name' => 'الأولى', 'code' => 'L1', 'order' => 1]);
        $secondLevel = Level::create(['name' => 'الثانية', 'code' => 'L2', 'order' => 2]);

        return [
            Section::create(['level_id' => $firstLevel->id, 'name' => 'أ', 'code' => 'L1-A', 'capacity' => 25]),
            Section::create(['level_id' => $secondLevel->id, 'name' => 'أ', 'code' => 'L2-A', 'capacity' => 25]),
            Section::create(['level_id' => $firstLevel->id, 'name' => 'ب', 'code' => 'L1-B', 'capacity' => 25]),
        ];
    }

    private function makeStudent(string $code, string $firstName): Student
    {
        return Student::create([
            'student_code' => $code,
            'first_name' => $firstName,
            'last_name' => 'اختبار',
            'gender' => 'male',
            'status' => 'active',
        ]);
    }

    private function enroll(
        Student $student,
        AcademicYear $year,
        Section $section,
        string $status = 'active'
    ): Enrollment {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'level_id' => $section->level_id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => $status,
        ]);
    }

    private function makeStudentManager()
    {
        $user = $this->makeUser('student_transfer_manager');
        $user->update(['is_active' => true]);
        $permission = Permission::create([
            'name' => 'manage_students',
            'display_name' => 'إدارة التلاميذ',
            'group' => 'Students',
        ]);
        $user->role->permissions()->attach($permission);

        return $user;
    }
}
