<?php
namespace App\Http\Controllers;

use App\Services\StudentService;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService    $studentService,
        protected EnrollmentService $enrollmentService,
    ) {}

    public function index(Request $request)
    {
        $students = $this->studentService->getStudentsWithCurrentEnrollment([
            'search'   => $request->get('search'),
            'level_id' => $request->get('level_id'),
            'per_page' => min((int) $request->get('per_page', 20), 100),
        ]);

        return response()->json($students);
    }

    public function show(int $id)
    {
        $student = $this->studentService->getStudentById($id);

        if (!$student) {
            return response()->json(['message' => 'الطالب غير موجود'], 404);
        }

        return response()->json($student);
    }

    public function store(Request $request)
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
            'mother_phone'        => 'nullable|string',
            'mother_email'        => 'nullable|email',
            'level_id'            => 'required|exists:levels,id',
            'section_name'        => 'nullable|string',
            'photo'               => 'nullable|image|max:2048',
        ]);

        try {
            // الصورة تُمرَّر كـ UploadedFile — التخزين داخل الـ Service ✅
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
            return response()->json(['message' => 'حدث خطأ أثناء التسجيل'], 500);
        }
    }

    public function reenroll(Request $request, int $id)
    {
        $validated = $request->validate([
            'level_id'     => 'required|exists:levels,id',
            'section_name' => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($id, $validated);

            return response()->json([
                'message'    => 'تم الترسيم بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'حدث خطأ أثناء الترسيم'], 500);
        }
    }
}
