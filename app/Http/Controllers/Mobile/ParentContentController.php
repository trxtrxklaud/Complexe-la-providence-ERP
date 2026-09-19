<?php

namespace App\Http\Controllers\Mobile;

use App\Models\BehaviorLog;
use App\Models\Enrollment;
use App\Models\HomeworkAssignment;
use App\Models\Student;
use App\Services\Mobile\MobileScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

/**
 * محتوى الواجبات والسلوك لكل ابن وليّ (نطاق أولياء عبر MobileScopeService).
 */
class ParentContentController extends Controller
{
    public function __construct(
        private MobileScopeService $scope,
    ) {}

    /** الواجبات المنشورة لأقسام ابن الولي. */
    public function homework(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);
        $enrollment = $this->activeEnrollment($student);

        if (! $enrollment) {
            return response()->json([]);
        }

        $assignments = HomeworkAssignment::query()
            ->where('section_id', $enrollment->section_id)
            ->published()
            ->orderByDesc('due_date')
            ->get();

        return response()->json(
            $assignments->map(fn (HomeworkAssignment $h) => [
                'id'           => $h->id,
                'subject'      => $h->subject,
                'title'        => $h->title,
                'description'  => $h->description,
                'due_date'     => $h->due_date?->toDateString(),
                'published_at' => $h->published_at?->toIso8601String(),
            ])
        );
    }

    /** سجل السلوك لكل ابن الولي. */
    public function behavior(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);
        $enrollment = $this->activeEnrollment($student);

        if (! $enrollment) {
            return response()->json([]);
        }

        $logs = BehaviorLog::query()
            ->where('enrollment_id', $enrollment->id)
            ->orderByDesc('recorded_at')
            ->get();

        return response()->json(
            $logs->map(fn (BehaviorLog $b) => [
                'id'          => $b->id,
                'type'        => $b->type,
                'note'        => $b->note,
                'recorded_at' => $b->recorded_at?->toIso8601String(),
            ])
        );
    }

    /* ---------- scoping ---------- */

    private function authorizeChild(Request $request, Student $student): void
    {
        if (! $this->scope->parentOwnsStudent($request->user(), $student->id)) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه البيانات');
        }
    }

    private function activeEnrollment(Student $student): ?Enrollment
    {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }
}
