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
    public function __construct() {}

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
                $path = $photoFile->store('students/photos', 'local');
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
            $section      = $this->resolveEnrollmentSection($data);

            $enrollment = Enrollment::create([
                'student_id'       => $student->id,
                'academic_year_id' => $academicYear->id,
                'level_id'         => $section->level_id,
                'section_id'       => $section->id,
                'enrollment_date'  => now(),
                'status'           => 'active',
                'notes'            => $data['notes'] ?? null,
            ]);

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

            $section = $this->resolveEnrollmentSection($data);

            $enrollment = Enrollment::create([
                'student_id'             => $student->id,
                'academic_year_id'       => $academicYear->id,
                'level_id'               => $section->level_id,
                'section_id'             => $section->id,
                'enrollment_date'        => now(),
                'status'                 => 'active',
                'previous_enrollment_id' => $previousEnrollment?->id,
                'notes'                  => $data['notes'] ?? null,
            ]);

            return $enrollment;
        });
    }

    /**
     * القسم هو مصدر الحقيقة، والمستوى يُشتقّ منه.
     *
     * سبب هذا الاتجاه: القسم ينتمي إلى مستوى واحد لا غير (sections.level_id)، فإذا
     * أرسلت الواجهة الاثنين معاً أمكن أن يتناقضا ويُسجَّل تلميذ في مستوى لا يحوي قسمه.
     * لذلك نقبل section_id ونستخرج منه level_id، ونرفض أي level_id مخالف بدل تجاهله.
     *
     * يبقى المسار القديم (level_id + section_name) مقبولاً حتى تُحوَّل بقيّة الشاشات.
     */
    private function resolveEnrollmentSection(array $data): Section
    {
        if (! empty($data['section_id'])) {
            $section = Section::find((int) $data['section_id']);

            if (! $section) {
                throw new \InvalidArgumentException('القسم المختار غير موجود');
            }

            if (! empty($data['level_id']) && (int) $data['level_id'] !== (int) $section->level_id) {
                throw new \InvalidArgumentException('القسم المختار لا ينتمي إلى المستوى المحدَّد');
            }

            return $section;
        }

        if (empty($data['level_id'])) {
            throw new \InvalidArgumentException('يجب اختيار القسم');
        }

        return $this->resolveSection((int) $data['level_id'], $data['section_name'] ?? 'أ');
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
