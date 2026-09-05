<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkEnrollRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * قوائم الأقسام: عرض، إدخال دفعي، تعديل، حذف، طباعة.
 */
class RosterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $section = Section::with('level')->findOrFail($validated['section_id']);
        $year = AcademicYear::findOrFail($validated['academic_year_id']);

        $students = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('section_id', $section->id)
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->filter(fn ($enrollment) => $enrollment->student !== null)
            ->sortBy(fn ($enrollment) => $enrollment->student->first_name)
            ->values()
            ->map(fn ($enrollment) => [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student->id,
                'student_code' => $enrollment->student->student_code,
                'first_name' => $enrollment->student->first_name,
                'last_name' => $enrollment->student->last_name,
                'father_name' => $enrollment->student->guardian_first_name,
                'mother_name' => $enrollment->student->mother_name ?? null,
                'father_phone' => $enrollment->student->guardian_phone,
                'mother_phone' => $enrollment->student->mother_phone,
            ]);

        return response()->json([
            'year' => $year->name,
            'level' => $section->level?->name ?? '',
            'section' => $section->name,
            'capacity' => (int) $section->capacity,
            'students' => $students,
        ]);
    }

    public function bulkStore(BulkEnrollRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->boolean('async')) {
            \App\Jobs\ProcessBulkEnrollment::dispatch($data, $request->user()?->id);

            return response()->json([
                'message' => 'تم إرسال عملية التسجيل الجماعي إلى قائمة الانتظار للمُعالجة في الخلفية.',
                'status' => 'queued',
            ], 202);
        }

        $result = (new \App\Jobs\ProcessBulkEnrollment($data, $request->user()?->id))->handle();

        return response()->json($result, 201);
    }

    public function updateStudent(Request $request, Enrollment $roster): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:120'],
            'last_name' => ['required', 'string', 'min:2', 'max:120'],
            'father_name' => ['nullable', 'string', 'max:120'],
            'mother_name' => ['nullable', 'string', 'max:120'],
            'father_phone' => ['nullable', 'string', 'max:20'],
            'mother_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $roster->load('student');

        if (!$roster->student) {
            return response()->json(['message' => 'التلميذ غير موجود.'], 404);
        }

        $roster->student->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'guardian_first_name' => $validated['father_name'] ?? null,
            'mother_name' => $validated['mother_name'] ?? null,
            'guardian_phone' => $validated['father_phone'] ?? null,
            'mother_phone' => $validated['mother_phone'] ?? null,
        ]);

        return response()->json([
            'message' => 'تم تحديث بيانات التلميذ.',
            'student' => [
                'enrollment_id' => $roster->id,
                'student_id' => $roster->student->id,
                'student_code' => $roster->student->student_code,
                'first_name' => $roster->student->first_name,
                'last_name' => $roster->student->last_name,
                'father_name' => $roster->student->guardian_first_name,
                'mother_name' => $roster->student->mother_name,
                'father_phone' => $roster->student->guardian_phone,
                'mother_phone' => $roster->student->mother_phone,
            ],
        ]);
    }

    public function destroy(Enrollment $roster): JsonResponse
    {
        $roster->delete();

        return response()->json(['message' => 'تم حذف التلميذ من القسم.']);
    }

    /**
     * توحيد الاسم للمقارنة: حذف التشكيل وتوحيد الألف والهاء والياء.
     */
    private function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value);
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $value);

        return mb_strtolower($value);
    }

    private function nextStudentCode(string $yearName): string
    {
        preg_match('/(\d{4})/', $yearName, $matches);
        $prefix = 'PRV-' . ($matches[1] ?? date('Y')) . '-';

        $sequence = (int) Student::where('student_code', 'like', $prefix . '%')->count();

        do {
            $sequence++;
            $code = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        } while (Student::where('student_code', $code)->exists());

        return $code;
    }
}
