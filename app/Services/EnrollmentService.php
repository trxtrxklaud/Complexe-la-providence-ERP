<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\Section;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentService
{
    /**
     * Enroll a new student with guardian and create initial enrollment record.
     * This replaces the flat data approach in the old StudentController.
     */
    public function enrollStudent(array $data, $photoPath = null): Enrollment
    {
        return DB::transaction(function () use ($data, $photoPath) {
            // 1. Create or find student
            $student = Student::create([
                'student_code'   => $this->generateStudentCode(),
                'first_name'     => $data['first_name'],
                'last_name'      => $data['last_name'],
                'dob'            => $data['dob'],
                'gender'         => $data['gender'],
                'photo'          => $photoPath,
                'notes'          => $data['notes'] ?? null,
                'status'         => 'active',
            ]);

            // 2. Create guardian(s)
            $guardian = Guardian::create([
                'first_name'   => $data['guardian_first_name'],
                'last_name'    => $data['guardian_last_name'],
                'phone'        => $data['guardian_phone'],
                'email'        => $data['guardian_email'] ?? null,
                'address'      => $data['address'],
                'mother_phone' => $data['mother_phone'] ?? null,
                'mother_email' => $data['mother_email'] ?? null,
            ]);

            // Link guardian as primary
            $student->guardians()->attach($guardian->id, [
                'relationship'       => 'primary',
                'is_primary_contact' => true,
            ]);

            // 3. Get active academic year
            $academicYear = AcademicYear::where('is_active', true)->firstOrFail();

            // 4. Get level and section
            $level = Level::findOrFail($data['level_id']);
            $section = Section::where('level_id', $level->id)
                              ->where('name', $data['section_name'] ?? 'أ')
                              ->firstOrFail();

            // 5. Create enrollment
            $enrollment = Enrollment::create([
                'student_id'           => $student->id,
                'academic_year_id'     => $academicYear->id,
                'level_id'             => $level->id,
                'section_id'           => $section->id,
                'enrollment_date'      => now(),
                'status'               => 'active',
                'notes'                => $data['notes'] ?? null,
            ]);

            // TODO in Phase 3: Generate initial student_fees based on fee_plans

            return $enrollment;
        });
    }

    private function generateStudentCode(): string
    {
        $year = now()->year;
        $random = strtoupper(Str::random(6));
        return "PRV-{$year}-{$random}";
    }
}
