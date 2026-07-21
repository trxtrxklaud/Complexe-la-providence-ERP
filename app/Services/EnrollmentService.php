<?php
namespace App\Services;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\Section;
use App\Models\AcademicYear;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnrollmentService
{
    public function __construct(private FeeService $feeService) {}

    public function enrollStudent(array $data, ?UploadedFile $photoFile = null): Enrollment
    {
        return DB::transaction(function () use ($data, $photoFile) {
            $student = Student::create([
                'student_code' => $this->generateStudentCode(),
                'first_name'   => $data['first_name'],
                'last_name'    => $data['last_name'],
                'dob'          => $data['dob'],
                'gender'       => $data['gender'],
                'notes'        => $data['notes'] ?? null,
                'status'       => 'active',
            ]);

            // الصورة تُخزَّن داخل الـ transaction — لو فشل أي شيء بعدها نحذفها
            if ($photoFile) {
                $path = $photoFile->store('students/photos', 'public');
                $student->update(['photo' => $path]);
            }

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
            $section      = $this->resolveSection($data['level_id'], $data['section_name'] ?? 'أ');

            $enrollment = Enrollment::create([
                'student_id'       => $student->id,
                'academic_year_id' => $academicYear->id,
                'level_id'         => $data['level_id'],
                'section_id'       => $section->id,
                'enrollment_date'  => now(),
                'status'           => 'active',
                'notes'            => $data['notes'] ?? null,
            ]);

            // توليد الرسوم تلقائياً ✅
            $this->feeService->generateFeesForEnrollment($enrollment);

            return $enrollment;
        });
    }

    public function reenrollStudent(int $studentId, array $data): Enrollment
    {
        return DB::transaction(function () use ($studentId, $data) {
            $student = Student::findOrFail($studentId);
            $academicYear = AcademicYear::where('is_active', true)->firstOrFail();

            $exists = Enrollment::where('student_id', $studentId)
                                ->where('academic_year_id', $academicYear->id)
                                ->exists(); // SoftDeletes scope → يتجاهل المحذوفة تلقائياً ✅

            if ($exists) {
                throw new \InvalidArgumentException('الطالب مُرسَّم بالفعل في السنة الدراسية الحالية');
            }

            // withTrashed() لضمان إيجاد السجل حتى لو محذوف ✅
            $previousEnrollment = Enrollment::withTrashed()
                                            ->where('student_id', $studentId)
                                            ->latest()
                                            ->first();

            $section = $this->resolveSection($data['level_id'], $data['section_name'] ?? 'أ');

            $enrollment = Enrollment::create([
                'student_id'             => $student->id,
                'academic_year_id'       => $academicYear->id,
                'level_id'               => $data['level_id'],
                'section_id'             => $section->id,
                'enrollment_date'        => now(),
                'status'                 => 'active',
                'previous_enrollment_id' => $previousEnrollment?->id,
                'notes'                  => $data['notes'] ?? null,
            ]);

            $this->feeService->generateFeesForEnrollment($enrollment);

            return $enrollment;
        });
    }

    private function resolveSection(int $levelId, string $sectionName): Section
    {
        $section = Section::where('level_id', $levelId)
                          ->where('name', $sectionName)
                          ->first();

        if (!$section) {
            throw new \InvalidArgumentException(
                'القسم "' . $sectionName . '" غير موجود في هذا المستوى'
            );
        }

        return $section;
    }

    private function generateStudentCode(): string
    {
        $attempts = 0;
        do {
            if (++$attempts > 10) {
                throw new \RuntimeException('فشل توليد كود طالب فريد');
            }
            $code = 'PRV-' . now()->year . '-' . strtoupper(Str::random(6));
        } while (Student::where('student_code', $code)->exists());

        return $code;
    }
}
