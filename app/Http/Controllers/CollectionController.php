<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function __construct(private readonly CollectionService $collectionService) {}

    public function years(): JsonResponse
    {
        $years = AcademicYear::orderByDesc('start_date')
            ->get(['id', 'name', 'is_active', 'start_date', 'end_date']);

        return response()->json($years);
    }

    public function sectionsByYear(AcademicYear $year): JsonResponse
    {
        $sections = Section::whereHas(
            'enrollments',
            fn ($q) => $q->where('academic_year_id', $year->id)
        )
            ->with('level:id,name')
            ->orderBy('name')
            ->get(['id', 'level_id', 'name']);

        return response()->json($sections);
    }

    public function studentsBySection(Section $section, Request $request): JsonResponse
    {
        $request->validate([
            'year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $enrollments = Enrollment::where('section_id', $section->id)
            ->where('academic_year_id', $request->integer('year_id'))
            ->with([
                'student:id,first_name,last_name,student_code',
                'student.guardians',
            ])
            ->get();

        $result = $enrollments->map(function ($e) {
            $guardian = $e->student?->guardians
                ?->sortByDesc(fn ($g) => $g->pivot->is_primary_contact ?? 0)
                ->first();

            return [
                'enrollment_id' => $e->id,
                'student' => [
                    'id'           => $e->student->id,
                    'first_name'   => $e->student->first_name,
                    'last_name'    => $e->student->last_name,
                    'student_code' => $e->student->student_code,
                ],
                'guardian' => $guardian ? [
                    'first_name' => $guardian->first_name,
                    'last_name'  => $guardian->last_name,
                    'phone'      => $guardian->phone,
                ] : null,
            ];
        });

        return response()->json($result);
    }

    public function collect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id'           => ['required', 'integer', 'exists:students,id'],
            'enrollment_id'        => ['required', 'integer', 'exists:enrollments,id'],
            'months'               => ['required', 'array', 'min:1'],
            'months.*'             => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'payment_date'         => ['required', 'date', 'before_or_equal:today'],
            'method'               => ['required', 'in:cash,bank_transfer,check,card'],
            'reference'            => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string', 'max:500'],
            'discount'             => ['nullable', 'numeric', 'min:0'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.fee_type_id'  => ['required', 'integer', 'exists:fee_types,id'],
            'items.*.amount'       => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $receipt = $this->collectionService->collect($validated, (int) auth()->id());

            return response()->json([
                'message' => 'تم تسجيل الاستخلاص بنجاح',
                'receipt' => $receipt,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'فشل تسجيل الاستخلاص'], 500);
        }
    }

    public function ledger(Enrollment $enrollment): JsonResponse
    {
        return response()->json([
            'enrollment_id' => $enrollment->id,
            'paid_months'   => $this->collectionService->getPaidMonths($enrollment->id),
            'ledger'        => $this->collectionService->monthLedger($enrollment->id),
            'year_months'   => $enrollment->academicYear
                ? $this->collectionService->getAcademicYearMonths($enrollment->academicYear)
                : [],
        ]);
    }
}
