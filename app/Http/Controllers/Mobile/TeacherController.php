<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentResult;
use App\Services\Mobile\MobileScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TeacherController — دفتر المعلّم المُنطاق على أقسامه فقط
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. كل مسار يفرض النطاق خادمياً عبر MobileScopeService.
| الكتابة هنا أكاديمية بحتة (حضور/نتائج/إعلانات) — لا تمسّ أي منطق مالي
| ولا Ledger/Payment/CashTransaction.
|
*/

class TeacherController extends Controller
{
    public function __construct(private MobileScopeService $scope) {}

    /** أقسام المعلّم (من pivot section_teacher عبر صفّه في employees). */
    public function sections(Request $request): JsonResponse
    {
        $ids = $this->scope->teacherSectionIds($request->user());

        $sections = Section::query()
            ->whereIn('id', $ids)
            ->with(['level:id,name'])
            ->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])
            ->get(['id', 'name', 'level_id'])
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'level' => $s->level?->name,
                'students_count' => $s->active_students_count,
            ]);

        return response()->json($sections);
    }

    /** قائمة تلاميذ القسم (roster) — قراءة فقط. */
    public function students(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $roster = $this->scope->sectionRoster($section->id)
            ->map(fn (Enrollment $e) => [
                'enrollment_id' => $e->id,
                'student_id' => $e->student?->id,
                'name' => trim(($e->student?->first_name ?? '').' '.($e->student?->last_name ?? '')),
                'student_code' => $e->student?->student_code,
            ])
            ->values();

        return response()->json($roster);
    }

    /** جلب سجلات حضور القسم لتاريخ محدد. */
    public function getAttendance(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $date = $request->query('date', now()->toDateString());
        $records = Attendance::where('section_id', $section->id)
            ->where('date', $date)
            ->get(['id', 'enrollment_id', 'status', 'note']);

        return response()->json([
            'date' => $date,
            'records' => $records,
        ]);
    }

    /** تسجيل حضور اليوم للقسم — upsert على (enrollment_id, date). */
    public function storeAttendance(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        // دعم نمط الكائن { "1079": "present" } أو المصفوفة entries
        if ($request->has('attendance') && is_array($request->attendance) && ! $request->has('entries')) {
            $entries = [];
            foreach ($request->attendance as $enrollmentId => $val) {
                $status = is_array($val) ? ($val['status'] ?? 'present') : $val;
                $note = is_array($val) ? ($val['note'] ?? null) : null;
                $entries[] = [
                    'enrollment_id' => (int) $enrollmentId,
                    'status' => in_array($status, ['present', 'absent', 'late', 'excused']) ? $status : 'present',
                    'note' => $note,
                ];
            }
            $request->merge(['entries' => $entries]);
        }

        $data = $request->validate([
            'date' => 'required|date',
            'entries' => 'required|array|min:1',
            'entries.*.enrollment_id' => 'required|integer',
            'entries.*.status' => 'required|in:present,absent,late,excused',
            'entries.*.note' => 'nullable|string|max:500',
        ]);

        $validEnrollmentIds = $this->scope->sectionRoster($section->id)->pluck('id')->all();
        $date = $data['date'];
        $userId = $request->user()->id;
        $saved = 0;

        DB::transaction(function () use ($data, $section, $validEnrollmentIds, $date, $userId, &$saved) {
            foreach ($data['entries'] as $entry) {
                // نطاق صارم: لا نقبل تلميذاً خارج roster القسم.
                if (! in_array((int) $entry['enrollment_id'], $validEnrollmentIds, true)) {
                    continue;
                }

                Attendance::updateOrCreate(
                    ['enrollment_id' => $entry['enrollment_id'], 'date' => $date],
                    [
                        'section_id' => $section->id,
                        'status' => $entry['status'],
                        'note' => $entry['note'] ?? null,
                        'recorded_by' => $userId,
                    ]
                );
                $saved++;
            }
        });

        return response()->json(['message' => 'تم حفظ الحضور', 'saved' => $saved]);
    }

    /** جلب نتائج القسم لمادة وفترة محددة. */
    public function getResults(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $subject = $request->query('subject');
        $term = $request->query('term');

        $rosterIds = $this->scope->sectionRoster($section->id)->pluck('id')->all();

        $query = StudentResult::whereIn('enrollment_id', $rosterIds);
        if ($subject) {
            $query->where('subject', $subject);
        }
        if ($term) {
            $query->where('term', $term);
        }

        $results = $query->get(['id', 'enrollment_id', 'subject', 'term', 'score', 'max_score', 'published_at']);

        return response()->json([
            'subject' => $subject,
            'term' => $term,
            'results' => $results,
        ]);
    }

    /** إدخال النتائج/الأعداد للقسم — upsert على (enrollment_id, subject, term). */
    public function storeResults(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        // دعم نمط الكائن { "1079": 17.5 } أو المصفوفة entries
        if ($request->has('grades') && is_array($request->grades) && ! $request->has('entries')) {
            $entries = [];
            foreach ($request->grades as $enrollmentId => $score) {
                $entries[] = [
                    'enrollment_id' => (int) $enrollmentId,
                    'score' => is_array($score) ? ($score['score'] ?? 0) : (float) $score,
                    'max_score' => is_array($score) ? ($score['max_score'] ?? 20) : 20,
                ];
            }
            $request->merge(['entries' => $entries]);
        }

        if (! $request->has('subject') || empty($request->subject)) {
            $request->merge(['subject' => 'الرياضيات']);
        }

        if (! $request->has('publish')) {
            $request->merge(['publish' => true]);
        }

        $data = $request->validate([
            'subject' => 'required|string|max:100',
            'term' => 'nullable|string|max:20',
            'publish' => 'boolean',
            'entries' => 'required|array|min:1',
            'entries.*.enrollment_id' => 'required|integer',
            'entries.*.score' => 'required|numeric|min:0',
            'entries.*.max_score' => 'nullable|numeric|min:1',
        ]);

        $validEnrollmentIds = $this->scope->sectionRoster($section->id)->pluck('id')->all();
        $userId = $request->user()->id;
        $publishedAt = ($data['publish'] ?? false) ? now() : null;
        $saved = 0;

        DB::transaction(function () use ($data, $validEnrollmentIds, $userId, $publishedAt, &$saved) {
            foreach ($data['entries'] as $entry) {
                if (! in_array((int) $entry['enrollment_id'], $validEnrollmentIds, true)) {
                    continue;
                }

                StudentResult::updateOrCreate(
                    [
                        'enrollment_id' => $entry['enrollment_id'],
                        'subject' => $data['subject'],
                        'term' => $data['term'] ?? null,
                    ],
                    [
                        'score' => $entry['score'],
                        'max_score' => $entry['max_score'] ?? 20,
                        'published_at' => $publishedAt,
                        'entered_by' => $userId,
                    ]
                );
                $saved++;
            }
        });

        return response()->json(['message' => 'تم حفظ النتائج', 'saved' => $saved]);
    }

    /** نشر إعلان لأحد أقسام المعلّم. */
    public function storeAnnouncement(Request $request, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $section);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'publish' => 'boolean',
        ]);

        $announcement = Announcement::create([
            'author_user_id' => $request->user()->id,
            'scope' => Announcement::SCOPE_SECTION,
            'section_id' => $section->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'published_at' => ($data['publish'] ?? true) ? now() : null,
        ]);

        return response()->json($announcement, 201);
    }

    /** يمنع أي وصول لقسم لا يُدرّسه المعلّم (403). */
    private function authorizeSection(Request $request, Section $section): void
    {
        if (! $this->scope->teacherOwnsSection($request->user(), $section->id)) {
            abort(403, 'عذراً، لا تملك صلاحية للوصول');
        }
    }
}
