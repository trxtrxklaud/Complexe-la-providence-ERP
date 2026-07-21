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
        $query = Student::with([
            'enrollments' => fn($q) => $q->where('status', 'active')
                                         ->with(['level', 'section', 'academicYear']),
            'guardians'   => fn($q) => $q->wherePivot('is_primary_contact', true),
        ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn(Builder $q) =>
                $q->where('first_name',    'like', "%{$search}%")
                  ->orWhere('last_name',   'like', "%{$search}%")
                  ->orWhere('student_code','like', "%{$search}%")
            );
        }

        if (!empty($filters['level_id'])) {
            $query->whereHas('enrollments', fn($q) =>
                $q->where('level_id', $filters['level_id'])
            );
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
