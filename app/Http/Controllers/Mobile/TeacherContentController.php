<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\BehaviorLog;
use App\Models\HomeworkAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Services\Mobile\MobileScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherContentController extends Controller
{
    public function __construct(
        private MobileScopeService $scope,
    ) {}

    /* ---------- الواجبات (Homework) ---------- */

    public function indexHomework(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        return response()->json(
            HomeworkAssignment::query()
                ->where('section_id', $section->id)
                ->with('createdBy:id,first_name,last_name')
                ->orderByDesc('due_date')
                ->get()
                ->map(fn (HomeworkAssignment $h) => $this->homeworkPayload($h))
        );
    }

    public function storeHomework(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $data = $request->validate([
            'subject'    => 'nullable|string|max:100',
            'title'      => 'required|string|max:150',
            'description'=> 'nullable|string|max:2000',
            'due_date'   => 'nullable|date',
            'publish'    => 'boolean',
        ]);

        $homework = DB::transaction(function () use ($request, $section, $data) {
            return HomeworkAssignment::create([
                'section_id'   => $section->id,
                'created_by'   => $request->user()->id,
                'subject'      => $data['subject'] ?? null,
                'title'        => $data['title'],
                'description'  => $data['description'] ?? null,
                'due_date'     => $data['due_date'] ?? null,
                'published_at' => ($data['publish'] ?? false) ? now() : null,
            ]);
        });

        return response()->json($this->homeworkPayload($homework), 201);
    }

    /* ---------- السلوك (Behavior) ---------- */

    public function indexBehavior(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        return response()->json(
            BehaviorLog::query()
                ->where('section_id', $section->id)
                ->with(['student:id,first_name,last_name', 'recordedBy:id,first_name,last_name'])
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn (BehaviorLog $b) => $this->behaviorPayload($b))
        );
    }

    public function storeBehavior(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $data = $request->validate([
            'enrollment_id' => 'required|integer',
            'type'          => ['required', Rule::in(BehaviorLog::$types)],
            'note'          => 'nullable|string|max:1000',
        ]);

        $roster = $this->scope->sectionRoster($section->id)->pluck('id')->all();

        $log = DB::transaction(function () use ($request, $section, $data, $roster) {
            if (! in_array((int) $data['enrollment_id'], $roster, true)) {
                abort(403, 'المتعلّم غير المقصود غير مصرح ضمن هذا القسم');
            }

            $enrollment = \App\Models\Enrollment::with('student:id,first_name,last_name')
                ->findOrFail($data['enrollment_id']);

            return BehaviorLog::create([
                'section_id'      => $section->id,
                'enrollment_id'   => $enrollment->id,
                'student_id'      => $enrollment->student_id,
                'type'            => $data['type'],
                'note'            => $data['note'] ?? null,
                'recorded_by'     => $request->user()->id,
                'recorded_at'     => now(),
            ]);
        });

        return response()->json($this->behaviorPayload($log), 201);
    }

    /* ---------- helpers ---------- */

    private function homeworkPayload(HomeworkAssignment $h): array
    {
        return [
            'id'           => $h->id,
            'section_id'   => $h->section_id,
            'subject'      => $h->subject,
            'title'        => $h->title,
            'description'  => $h->description,
            'due_date'     => $h->due_date?->toDateString(),
            'published_at' => $h->published_at?->toIso8601String(),
            'created_by'   => $h->createdBy ? trim(($h->createdBy->first_name ?? '').' '.($h->createdBy->last_name ?? '')) : null,
        ];
    }

    private function behaviorPayload(BehaviorLog $b): array
    {
        return [
            'id'            => $b->id,
            'section_id'    => $b->section_id,
            'enrollment_id' => $b->enrollment_id,
            'student'       => $b->student ? [
                'id'   => $b->student->id,
                'name' => trim(($b->student->first_name ?? '').' '.($b->student->last_name ?? '')),
            ] : null,
            'type'        => $b->type,
            'note'        => $b->note,
            'recorded_by' => $b->recordedBy ? trim(($b->recordedBy->first_name ?? '').' '.($b->recordedBy->last_name ?? '')) : null,
            'recorded_at' => $b->recorded_at?->toIso8601String(),
        ];
    }

    /* ---------- scoping (mirrors TeacherController) ---------- */

    private function authorizeSection(Request $request, Section $section): void
    {
        if (! $this->scope->teacherOwnsSection($request->user(), $section->id)) {
            abort(403, 'لا تملك حق الوصول إلى هذا القسم');
        }
    }
}
