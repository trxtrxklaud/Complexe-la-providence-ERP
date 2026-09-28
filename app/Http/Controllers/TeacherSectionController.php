<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\Section;
use App\Models\SectionTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherSectionController extends Controller
{
    /**
     * GET /api/teacher-sections/teachers
     * قائمة المعلمين
     */
    public function getTeachers(): JsonResponse
    {
        $teachers = Employee::with(['user.role'])
            ->where(function ($q) {
                $q->whereIn('staff_type', ['monthly_teacher', 'hourly_teacher'])
                  ->orWhereHas('user.role', function ($rq) {
                      $rq->where('name', 'teacher');
                  });
            })
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => trim($teacher->first_name . ' ' . $teacher->last_name) ?: $teacher->full_name,
                    'phone' => $teacher->phone,
                    'email' => $teacher->email,
                    'staff_type' => $teacher->staff_type,
                ];
            });

        return response()->json($teachers);
    }

    /**
     * GET /api/teacher-sections/sections
     * قائمة الأقسام مع المعلومات
     */
    public function getSections(): JsonResponse
    {
        $year = AcademicYear::where('is_active', true)->latest('id')->first();

        $sections = Section::with(['level'])
            ->withCount([
                'enrollments as student_count' => function ($q) use ($year) {
                    $q->where('status', 'active');
                    if ($year) {
                        $q->where('academic_year_id', $year->id);
                    }
                },
                'sectionTeachers as teacher_count',
            ])
            ->orderBy('level_id')
            ->orderBy('name')
            ->get()
            ->map(function ($section) {
                return [
                    'id' => $section->id,
                    'name' => $section->name,
                    'level' => [
                        'id' => $section->level?->id,
                        'name' => $section->level?->name,
                    ],
                    'student_count' => (int) $section->student_count,
                    'teacher_count' => (int) $section->teacher_count,
                ];
            });

        return response()->json($sections);
    }

    /**
     * GET /api/teacher-sections/teachers/{employeeId}
     * أقسام معلم محدد
     */
    public function getTeacherSections(int $employeeId): JsonResponse
    {
        $teacher = Employee::with('user')->findOrFail($employeeId);

        $sections = SectionTeacher::where('employee_id', $employeeId)
            ->with(['section.level'])
            ->get()
            ->map(function ($st) {
                return [
                    'id' => $st->id,
                    'section' => [
                        'id' => $st->section?->id,
                        'name' => $st->section?->name,
                        'level' => [
                            'id' => $st->section?->level?->id,
                            'name' => $st->section?->level?->name,
                        ],
                    ],
                    'subject' => $st->subject,
                    'created_at' => $st->created_at,
                ];
            });

        return response()->json([
            'teacher' => [
                'id' => $teacher->id,
                'name' => trim($teacher->first_name . ' ' . $teacher->last_name) ?: $teacher->full_name,
                'phone' => $teacher->phone,
                'email' => $teacher->email,
            ],
            'sections' => $sections,
        ]);
    }

    /**
     * POST /api/teacher-sections/assign
     * إسناد قسم لمعلم
     */
    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'section_id' => 'required|exists:sections,id',
            'subject' => 'nullable|string|max:100',
        ]);

        // التحقق من عدم التكرار لنفس المعلم
        $exists = SectionTeacher::where('employee_id', $validated['employee_id'])
            ->where('section_id', $validated['section_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'هذا القسم مسند بالفعل لهذا المعلم',
            ], 422);
        }

        // إنشاء الإسناد
        $sectionTeacher = SectionTeacher::create([
            'employee_id' => $validated['employee_id'],
            'section_id' => $validated['section_id'],
            'subject' => $validated['subject'] ?? null,
        ]);

        $sectionTeacher->load('section.level');

        // جلب المعلمين الآخرين في هذا القسم للتنبيه
        $otherTeachers = SectionTeacher::where('section_id', $validated['section_id'])
            ->where('employee_id', '!=', $validated['employee_id'])
            ->with(['employee'])
            ->get()
            ->map(function ($st) {
                return [
                    'name' => $st->employee ? trim($st->employee->first_name . ' ' . $st->employee->last_name) : 'معلم',
                    'subject' => $st->subject,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sectionTeacher->id,
                'section' => [
                    'id' => $sectionTeacher->section?->id,
                    'name' => $sectionTeacher->section?->name,
                    'level' => [
                        'id' => $sectionTeacher->section?->level?->id,
                        'name' => $sectionTeacher->section?->level?->name,
                    ],
                ],
                'subject' => $sectionTeacher->subject,
                'created_at' => $sectionTeacher->created_at,
            ],
            'other_teachers' => $otherTeachers,
        ], 201);
    }

    /**
     * DELETE /api/teacher-sections/remove
     * إلغاء إسناد قسم من معلم
     */
    public function remove(Request $request): JsonResponse
    {
        if ($request->filled('id')) {
            $deleted = SectionTeacher::where('id', $request->integer('id'))->delete();
        } else {
            $validated = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'section_id' => 'required|exists:sections,id',
            ]);

            $deleted = SectionTeacher::where('employee_id', $validated['employee_id'])
                ->where('section_id', $validated['section_id'])
                ->delete();
        }

        if ($deleted === 0) {
            return response()->json([
                'error' => 'الإسناد غير موجود',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء إسناد القسم بنجاح',
        ]);
    }

    /**
     * DELETE /api/teacher-sections/teachers/{employeeId}
     * حذف كلي لأقسام معلم مع إمكانية نقلها لمعلم بديل
     */
    public function deleteTeacher(Request $request, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'replacement_employee_id' => 'nullable|exists:employees,id',
        ]);

        $teacher = Employee::findOrFail($employeeId);

        // جلب كافة أقسام المعلم الحالية
        $sections = SectionTeacher::where('employee_id', $employeeId)->get();
        $transferredCount = 0;

        // إذا تم تحديد معلم بديل، ننقل الأقسام إليه دون تكرار
        if (!empty($validated['replacement_employee_id']) && $sections->count() > 0) {
            $replacementId = (int) $validated['replacement_employee_id'];

            foreach ($sections as $st) {
                $exists = SectionTeacher::where('employee_id', $replacementId)
                    ->where('section_id', $st->section_id)
                    ->exists();

                if (! $exists) {
                    SectionTeacher::create([
                        'employee_id' => $replacementId,
                        'section_id'  => $st->section_id,
                        'subject'     => $st->subject,
                    ]);
                    $transferredCount++;
                }
            }
        }

        // حذف كافة إسنادات المعلم الأصلي
        SectionTeacher::where('employee_id', $employeeId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء إسناد كافة أقسام المعلم بنجاح' . ($transferredCount > 0 ? " ونقل {$transferredCount} قسم(اً) للمعلم البديل" : ''),
            'transferred_count' => $transferredCount,
        ]);
    }
}
