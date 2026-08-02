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

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_search_supports_the_legacy_get_fields(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $matchingStudent = Student::create([
            'student_code'   => 'CNTE-001',
            'first_name'     => 'أحمد',
            'last_name'      => 'بن صالح',
            'dob'            => '2016-04-12',
            'gender'         => 'male',
            'guardian_phone' => '22123456',
            'status'         => 'active',
        ]);

        Enrollment::create([
            'student_id'       => $matchingStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        Student::create([
            'student_code'   => 'CNTE-999',
            'first_name'     => 'سليم',
            'last_name'      => 'العبيدي',
            'dob'            => '2015-01-01',
            'gender'         => 'male',
            'guardian_phone' => '99999999',
            'status'         => 'active',
        ]);

        $this->getJson('/api/students?'.http_build_query([
            'level'        => $section->id,
            'student_name' => 'أحمد بن صالح',
            'phone'        => '2212',
            'birthday'     => '2016-04-12',
            'year'         => $year->id,
            'cnte'         => 'CNTE-001',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingStudent->id);
    }

    public function test_student_search_options_return_sections_and_years(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $this->getJson('/api/students/search-options')
            ->assertOk()
            ->assertJsonPath('levels.0.id', $section->id)
            ->assertJsonPath('levels.0.label', $level->name.' '.$section->name)
            ->assertJsonPath('years.0.id', $year->id);
    }

    public function test_selecting_a_section_returns_all_active_students_in_that_section(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        foreach (['أحمد', 'مريم'] as $index => $firstName) {
            $student = Student::create([
                'student_code' => 'CLASS-'.$index,
                'first_name'   => $firstName,
                'last_name'    => 'اختبار',
                'gender'       => $index === 0 ? 'male' : 'female',
                'status'       => 'active',
            ]);

            Enrollment::create([
                'student_id'       => $student->id,
                'academic_year_id' => $year->id,
                'level_id'         => $level->id,
                'section_id'       => $section->id,
                'enrollment_date'  => '2025-09-01',
                'status'           => 'active',
            ]);
        }

        $this->getJson('/api/students?level='.$section->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_selected_student_detail_endpoints_are_available_to_student_managers(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code'        => 'DETAIL-001',
            'first_name'          => 'سارة',
            'last_name'           => 'بن عمر',
            'gender'              => 'female',
            'guardian_first_name' => 'منصف',
            'guardian_last_name'  => 'بن عمر',
            'guardian_phone'      => '22112233',
            'status'              => 'active',
        ]);

        $this->getJson('/api/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('guardian_phone', '22112233');

        $this->getJson('/api/students/'.$student->id.'/fees')
            ->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/students/'.$student->id.'/balance')
            ->assertOk()
            ->assertJsonPath('balance', 0);
    }

    private function makeSection(): array
    {
        $level = Level::create([
            'name'  => 'السنة الأولى',
            'code'  => 'L1',
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name'     => 'أ',
            'code'     => 'L1-A',
            'capacity' => 25,
        ]);

        return [$level, $section];
    }

    private function makeStudentManager()
    {
        $user = $this->makeUser('student_manager');
        $user->update(['is_active' => true]);
        $permission = Permission::create([
            'name'         => 'manage_students',
            'display_name' => 'إدارة الطلاب',
            'group'        => 'Students',
        ]);
        $user->role->permissions()->attach($permission);

        return $user;
    }
}
