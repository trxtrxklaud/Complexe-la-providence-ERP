<?php

namespace App\Http\Controllers;

use App\Services\StudentService;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    protected StudentService $studentService;
    protected EnrollmentService $enrollmentService;

    public function __construct(StudentService $studentService, EnrollmentService $enrollmentService)
    {
        $this->studentService = $studentService;
        $this->enrollmentService = $enrollmentService;
    }

    public function index(Request $request)
    {
        $students = $this->studentService->getStudentsWithCurrentEnrollment([
            'search' => $request->get('search'),
            'level_id' => $request->get('level_id'),
        ]);

        return response()->json($students);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'           => 'required|string|max:255',
            'last_name'            => 'required|string|max:255',
            'dob'                  => 'required|date',
            'gender'               => 'required|in:male,female',
            'notes'                => 'nullable|string',
            'guardian_first_name'  => 'required|string|max:255',
            'guardian_last_name'   => 'required|string|max:255',
            'guardian_phone'       => 'required|string|max:20',
            'guardian_email'       => 'nullable|email',
            'address'              => 'required|string',
            'mother_phone'         => 'nullable|string',
            'mother_email'         => 'nullable|email',
            'level_id'             => 'required|exists:levels,id',
            'section_name'         => 'nullable|string',
            'photo'                => 'nullable|image|max:2048',
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('students/photos', 'public');
        }

        try {
            $enrollment = $this->enrollmentService->enrollStudent($validated, $photoPath);

            return response()->json([
                'message'    => 'تم تسجيل التلميذ بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'حدث خطأ أثناء التسجيل',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
