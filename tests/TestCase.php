<?php

namespace Tests;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function makeUser(string $roleName = 'admin'): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['display_name' => $roleName]
        );

        $suffix = uniqid();

        return User::create([
            'first_name' => 'منير',
            'last_name'  => 'الهمامي',
            'username'   => 'cashier_' . $suffix,
            'email'      => 'cashier_' . $suffix . '@test.local',
            'password'   => 'secret123',
            'role_id'    => $role->id,
        ]);
    }

    protected function makeAcademicYear(string $name = '2025-2026'): AcademicYear
    {
        return AcademicYear::create([
            'name'       => $name,
            'start_date' => '2025-09-01',
            'end_date'   => '2026-06-30',
            'is_active'  => true,
        ]);
    }

    protected function makeEnrollment(?AcademicYear $year = null, ?Student $student = null): Enrollment
    {
        $year ??= $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name'  => 'السنة الأولى',
            'code'  => 'L' . substr($suffix, -6),
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name'     => 'أ',
            'code'     => 'S' . substr($suffix, -6),
            'capacity' => 25,
        ]);

        $student ??= Student::create([
            'student_code' => 'STU-' . substr($suffix, -6),
            'first_name'   => 'أحمد',
            'last_name'    => 'بن صالح',
            'gender'       => 'male',
            'status'       => 'active',
        ]);

        return Enrollment::create([
            'student_id'       => $student->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);
    }

    protected function makeFeeType(string $nameAr = 'القسط الشهري', float $price = 240): FeeType
    {
        return FeeType::create([
            'name_ar'   => $nameAr,
            'price'     => $price,
            'is_active' => true,
        ]);
    }
}
