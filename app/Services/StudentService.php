<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;

class StudentService
{
    /**
     * Get students with their current active enrollment (for dashboard & lists).
     */
    public function getStudentsWithCurrentEnrollment(array $filters = [])
    {
        $query = Student::with([
            'enrollments' => function ($q) {
                $q->where('status', 'active')
                  ->with(['level', 'section', 'academicYear']);
            },
            'guardians' => function ($q) {
                $q->wherePivot('is_primary_contact', true);
            }
        ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('student_code', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['level_id'])) {
            $query->whereHas('enrollments', fn($q) => 
                $q->where('level_id', $filters['level_id'])
            );
        }

        return $query->latest()->paginate(20);
    }

    public function getStudentBalance(Student $student): float
    {
        $paymentService = app(PaymentService::class);
        return $paymentService->getStudentBalance($student->id);
    }
}
