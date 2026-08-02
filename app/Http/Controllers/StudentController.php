<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferStudentsRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService $studentService,
        protected EnrollmentService $enrollmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $students = $this->studentService->getStudentsWithCurrentEnrollment([
            'search' => $request->get('student_name', $request->get('search')),
            'phone' => $request->get('phone'),
            'dob' => $request->get('birthday'),
            'student_code' => $request->get('cnte'),
            'level_id' => $request->get('level_id'),
            'section_id' => $request->get('level'),
            'academic_year_id' => $request->get('year'),
            'per_page' => min((int) $request->get('per_page', 20), 100),
        ]);

        return response()->json($students);
    }

    public function searchOptions(): JsonResponse
    {
        $sections = Section::with('level:id,name')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get(['id', 'level_id', 'name'])
            ->map(fn (Section $section) => [
                'id' => $section->id,
                'label' => trim(($section->level?->name ? $section->level->name.' ' : '').$section->name),
            ]);

        return response()->json([
            'levels' => $sections,
            'years' => AcademicYear::orderByDesc('start_date')->get(['id', 'name']),
        ]);
    }

    public function transferRoster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $students = Enrollment::query()
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('section_id', $validated['section_id'])
            ->where('status', 'active')
            ->with([
                'student.guardians' => fn ($query) => $query->orderByDesc('guardian_student.is_primary_contact'),
            ])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->sortBy(fn (Enrollment $enrollment) => $enrollment->student->first_name.' '.$enrollment->student->last_name)
            ->values()
            ->map(function (Enrollment $enrollment) {
                $student = $enrollment->student;
                $guardian = $student->guardians->first();

                return [
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'dob' => $student->dob?->toDateString(),
                    'gender' => $student->gender,
                    'guardian_name' => trim(implode(' ', array_filter([
                        $guardian?->first_name ?? $student->guardian_first_name,
                        $guardian?->last_name ?? $student->guardian_last_name,
                    ]))),
                    'mother_name' => $student->mother_name,
                    'phone' => $guardian?->phone ?? $student->guardian_phone ?? $student->mother_phone,
                ];
            });

        return response()->json(['students' => $students]);
    }

    public function transfer(TransferStudentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transferred = DB::transaction(function () use ($data) {
            $destination = Section::query()->lockForUpdate()->findOrFail($data['destination_section_id']);

            $enrollments = Enrollment::query()
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('section_id', $data['source_section_id'])
                ->where('status', 'active')
                ->whereIn('student_id', $data['student_ids'])
                ->lockForUpdate()
                ->get();

            $matchedStudentIds = $enrollments->pluck('student_id')->unique()->values();

            if ($matchedStudentIds->count() !== count($data['student_ids'])) {
                throw ValidationException::withMessages([
                    'student_ids' => ['بعض التلاميذ المحددين لا ينتمون إلى القسم المصدر في السنة المختارة.'],
                ]);
            }

            $destinationEnrollmentIds = Enrollment::query()
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('section_id', $destination->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->pluck('id');

            if (
                $destination->capacity > 0
                && $destinationEnrollmentIds->count() + $matchedStudentIds->count() > $destination->capacity
            ) {
                throw ValidationException::withMessages([
                    'destination_section_id' => ['لا تتسع سعة القسم الوجهة لكل التلاميذ المحددين.'],
                ]);
            }

            Enrollment::query()
                ->whereKey($enrollments->modelKeys())
                ->update([
                    'level_id' => $destination->level_id,
                    'section_id' => $destination->id,
                ]);

            return $matchedStudentIds->count();
        });

        return response()->json([
            'transferred' => $transferred,
            'message' => "تم نقل {$transferred} تلميذ بنجاح.",
        ]);
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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'dob' => 'required|date',
            'gender' => 'required|in:male,female',
            'notes' => 'nullable|string',
            'guardian_first_name' => 'required|string|max:255',
            'guardian_last_name' => 'required|string|max:255',
            'guardian_phone' => 'required|string|max:20',
            'guardian_email' => 'nullable|email',
            'address' => 'required|string',
            'mother_phone' => 'nullable|string|max:20',
            'mother_email' => 'nullable|email',
            'level_id' => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        try {
            $enrollment = $this->enrollmentService->enrollStudent(
                $validated,
                $request->file('photo')
            );

            return response()->json([
                'message' => 'تم تسجيل التلميذ بنجاح',
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
            'last_name' => 'sometimes|string|max:255',
            'dob' => 'sometimes|date',
            'gender' => 'sometimes|in:male,female',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive,transferred',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
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
            'level_id' => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);

            return response()->json([
                'message' => 'تم التسجيل بنجاح',
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
            'level_id' => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);

            return response()->json([
                'message' => 'تم الترسيم بنجاح',
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
