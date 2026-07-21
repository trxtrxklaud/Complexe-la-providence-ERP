<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\StudentService;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService    $studentService,
        protected EnrollmentService $enrollmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $students = $this->studentService->getStudentsWithCurrentEnrollment([
            'search'   => $request->get('search'),
            'level_id' => $request->get('level_id'),
            'per_page' => min((int) $request->get('per_page', 20), 100),
        ]);

        return response()->json($students);
    }

    // FIX: Route Model Binding instead of int $id + manual lookup
    public function show(Student $student): JsonResponse
    {
        return response()->json(
            $student->load(['enrollments.level', 'enrollments.section', 'guardians'])
        );
    }

    // store() = create new student + immediate enrollment (one-step for new students)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'          => 'required|string|max:255',
            'last_name'           => 'required|string|max:255',
            'dob'                 => 'required|date',
            'gender'              => 'required|in:male,female',
            'notes'               => 'nullable|string',
            'guardian_first_name' => 'required|string|max:255',
            'guardian_last_name'  => 'required|string|max:255',
            'guardian_phone'      => 'required|string|max:20',
            'guardian_email'      => 'nullable|email',
            'address'             => 'required|string',
            'mother_phone'        => 'nullable|string|max:20',
            'mother_email'        => 'nullable|email',
            'level_id'            => 'required|exists:levels,id',
            'section_name'        => 'nullable|string',
            'photo'               => 'nullable|image|max:2048',
        ]);

        try {
            $enrollment = $this->enrollmentService->enrollStudent(
                $validated,
                $request->file('photo')
            );

            return response()->json([
                'message'    => 'تم تسجيل التلميذ بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'حدث خطأ أثناء التسجيل'], 500);
        }
    }

    // NEW: update student profile only (not enrollment)
    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'dob'        => 'sometimes|date',
            'gender'     => 'sometimes|in:male,female',
            'notes'      => 'nullable|string',
            'status'     => 'sometimes|in:active,inactive,transferred',
            'photo'      => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            if ($student->photo) {
                Storage::disk('public')->delete($student->photo);
            }
            $validated['photo'] = $request->file('photo')->store('students/photos', 'public');
        }

        $student->update($validated);

        return response()->json($student->fresh());
    }

    // NEW: delete student only if no active enrollment
    public function destroy(Student $student): JsonResponse
    {
        $hasActiveEnrollment = $student->enrollments()
            ->where('status', 'active')
            ->exists();

        if ($hasActiveEnrollment) {
            return response()->json([
                'message' => 'لا يمكن حذف طالب لديه تسجيل نشط',
            ], 422);
        }

        if ($student->photo) {
            Storage::disk('public')->delete($student->photo);
        }

        $student->delete();

        return response()->json(null, 204);
    }

    // NEW: enroll existing student in current academic year
    public function enroll(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'level_id'     => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);

            return response()->json([
                'message'    => 'تم التسجيل بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'حدث خطأ أثناء التسجيل'], 500);
        }
    }

    // FIX: was int $id — now Route Model Binding
    public function reenroll(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'level_id'     => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);

            return response()->json([
                'message'    => 'تم الترسيم بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'حدث خطأ أثناء الترسيم'], 500);
        }
    }
}
