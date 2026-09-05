<?php

namespace App\Services\Mobile;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\SectionTeacher;
use App\Models\Student;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| MobileScopeService — حلّ نطاق «أبنائي / أقسامي» لطبقة الجوال
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. لا يعدّل FamilyService ولا أي منطق عائلي — يعيد استعمال
| FamilyService::normalizePhone() ثابتاً فقط (نفس قاعدة آخر 8 أرقام).
|
|   - الوليّ: أبناؤه = التلاميذ الذين يطابق هاتف وليّهم/أمّهم هاتفَ حساب الوليّ.
|   - المعلّم: أقسامه = صفوف section_teacher المربوطة بصفّه في employees.
|
| كل قرارات النطاق تُتّخذ هنا خادمياً؛ الـControllers تستدعيها ولا تثق بالعميل.
|
*/

class MobileScopeService
{
    /**
     * أرقام معرّفات التلاميذ الذين يملك هذا الوليّ حقّ رؤيتهم.
     *
     * @return array<int, int>
     */
    public function childStudentIds(User $parent): array
    {
        $phone = FamilyService::normalizePhone($parent->phone);

        if (! $phone) {
            return [];
        }

        return Student::query()
            ->select('id', 'guardian_phone', 'mother_phone')
            ->get()
            ->filter(fn (Student $st) => FamilyService::normalizePhone($st->guardian_phone) === $phone
                || FamilyService::normalizePhone($st->mother_phone) === $phone)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * هل يملك الوليّ حقّ رؤية هذا التلميذ؟
     */
    public function parentOwnsStudent(User $parent, int $studentId): bool
    {
        return in_array($studentId, $this->childStudentIds($parent), true);
    }

    /**
     * صفّ الموظّف (employees) المربوط بحساب المعلّم، أو null.
     */
    public function teacherEmployee(User $teacher): ?Employee
    {
        return Employee::where('user_id', $teacher->id)->first();
    }

    /**
     * أرقام معرّفات الأقسام التي يُدرّسها هذا المعلّم.
     *
     * @return array<int, int>
     */
    public function teacherSectionIds(User $teacher): array
    {
        $employee = $this->teacherEmployee($teacher);

        if (! $employee) {
            return [];
        }

        return SectionTeacher::where('employee_id', $employee->id)
            ->pluck('section_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * هل يُدرّس المعلّم هذا القسم؟
     */
    public function teacherOwnsSection(User $teacher, int $sectionId): bool
    {
        return in_array($sectionId, $this->teacherSectionIds($teacher), true);
    }

    /**
     * تسجيلات القسم النشطة (roster) — قراءة فقط، مربوطة بالسنة النشطة عبر الاستعلام.
     *
     * @return Collection<int, Enrollment>
     */
    public function sectionRoster(int $sectionId): Collection
    {
        return Enrollment::query()
            ->where('section_id', $sectionId)
            ->where('status', 'active')
            ->with(['student:id,first_name,last_name,student_code'])
            ->get();
    }
}
