<?php
namespace App\Services;

use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Builder;

class StudentService
{
    public function __construct(private PaymentService $paymentService) {}

    public function getStudentsWithCurrentEnrollment(array $filters = [])
    {
        $filterEnrollments = function ($enrollmentQuery) use ($filters) {
            if (! empty($filters['level_id'])) {
                $enrollmentQuery->where('level_id', $filters['level_id']);
            }

            if (! empty($filters['section_id'])) {
                $enrollmentQuery->where('section_id', $filters['section_id']);
            }

            if (! empty($filters['academic_year_id'])) {
                $enrollmentQuery->where('academic_year_id', $filters['academic_year_id']);
            } else {
                $enrollmentQuery->where('status', 'active');
            }

            return $enrollmentQuery;
        };

        $query = Student::with([
            'enrollments' => fn ($enrollmentQuery) =>
                $filterEnrollments($enrollmentQuery)->with(['level', 'section', 'academicYear']),
            'guardians'   => fn($q) => $q->wherePivot('is_primary_contact', true),
        ]);

        if (! empty($filters['search'])) {
            $terms = preg_split('/\s+/u', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($terms as $term) {
                $query->where(fn (Builder $q) =>
                    $q->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('student_code', 'like', "%{$term}%")
                );
            }
        }

        if (! empty($filters['phone'])) {
            $phone = $filters['phone'];
            $query->where(fn (Builder $q) =>
                $q->where('guardian_phone', 'like', "%{$phone}%")
                    ->orWhere('mother_phone', 'like', "%{$phone}%")
                    ->orWhereHas('guardians', fn (Builder $guardianQuery) =>
                        $guardianQuery->where('phone', 'like', "%{$phone}%")
                            ->orWhere('mother_phone', 'like', "%{$phone}%")
                    )
            );
        }

        if (! empty($filters['dob'])) {
            $query->whereDate('dob', $filters['dob']);
        }

        if (! empty($filters['student_code'])) {
            $query->where('student_code', 'like', '%'.$filters['student_code'].'%');
        }

        $hasEnrollmentFilter = ! empty($filters['level_id'])
            || ! empty($filters['section_id'])
            || ! empty($filters['academic_year_id']);

        if ($hasEnrollmentFilter) {
            $query->whereHas('enrollments', $filterEnrollments);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);
        return $query->latest()->paginate($perPage);
    }

    public function getStudentById(int $id): ?Student
    {
        return Student::with([
            'enrollments.level',
            'enrollments.section',
            'enrollments.academicYear',
            'guardians',
        ])->find($id);
    }

    public function getStudentBalance(Student $student): float
    {
        return $this->paymentService->getStudentBalance($student->id); // DI بدل app() ✅
    }
}
