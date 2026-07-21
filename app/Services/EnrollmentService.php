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
    public function enrollStudent(array $data, $photoPath = null): Enrollment
    {
        return DB::transaction(function () use ($data, $photoPath) {
            $student = Student::create([
                'student_code' => $this->generateStudentCode(),
                'first_name'   => $data['first_name'],
                'last_name'    => $data['last_name'],
                'dob'          => $data['dob'],
                'gender'       => $data['gender'],
                'photo'        => $photoPath,
                'notes'        => $data['notes'] ?? null,
                'status'       => 'active',
            ]);

            $guardian = Guardian::create([
                'first_name'   => $data['guardian_first_name'],
                'last_name'    => $data['guardian_last_name'],
                'phone'        => $data['guardian_phone'],
                'email'        => $data['guardian_email'] ?? null,
                'address'      => $data['address'],
                'mother_phone' => $data['mother_phone'] ?? null,
                'mother_email' => $data['mother_email'] ?? null,
            ]);

            $student->guardians()->attach($guardian->id, [
                'relationship'       => 'primary',
                'is_primary_contact' => true,
            ]);

            $academicYear = AcademicYear::where('is_active', true)->firstOrFail();
            $level        = Level::findOrFail($data['level_id']);

            $section = Section::where('level_id', $level->id)
                              ->where('name', $data['section_name'] ?? 'أ')
                              ->first();

            if (!$section) {
                throw new \InvalidArgumentException(
                    'القسم "' . ($data['section_name'] ?? 'أ') . '" غير موجود في هذا المستوى'
                );
            }

            return Enrollment::create([
                'student_id'       => $student->id,
                'academic_year_id' => $academicYear->id,
                'level_id'         => $level->id,
                'section_id'       => $section->id,
                'enrollment_date'  => now(),
                'status'           => 'active',
                'notes'            => $data['notes'] ?? null,
            ]);
        });
    }

    public function reenrollStudent(int $studentId, array $data): Enrollment
    {
        return DB::transaction(function () use ($studentId, $data) {
            $student            = Student::findOrFail($studentId);
            $previousEnrollment = Enrollment::where('student_id', $studentId)->latest()->firstOrFail();
            $academicYear       = AcademicYear::where('is_active', true)->firstOrFail();

            $exists = Enrollment::where('student_id', $studentId)
                                ->where('academic_year_id', $academicYear->id)
                                ->exists();

            if ($exists) {
                throw new \InvalidArgumentException('الطالب مُرسَّم بالفعل في السنة الدراسية الحالية');
            }

            $level   = Level::findOrFail($data['level_id']);
            $section = Section::where('level_id', $level->id)
                              ->where('name', $data['section_name'] ?? 'أ')
                              ->first();

            if (!$section) {
                throw new \InvalidArgumentException('القسم غير موجود في هذا المستوى');
            }

            return Enrollment::create([
                'student_id'             => $student->id,
                'academic_year_id'       => $academicYear->id,
                'level_id'               => $level->id,
                'section_id'             => $section->id,
                'enrollment_date'        => now(),
                'status'                 => 'active',
                'previous_enrollment_id' => $previousEnrollment->id,
                'notes'                  => $data['notes'] ?? null,
            ]);
        });
    }

    private function generateStudentCode(): string
    {
        do {
            $code = 'PRV-' . now()->year . '-' . strtoupper(Str::random(6));
        } while (Student::where('student_code', $code)->exists());

        return $code;
    }
}
