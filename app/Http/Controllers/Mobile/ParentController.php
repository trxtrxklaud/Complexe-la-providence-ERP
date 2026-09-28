<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\CalendarEvent;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentResult;
use App\Services\CollectionService;
use App\Services\Mobile\MobileScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| ParentController — قراءات الوليّ المُنطاقة على أبنائه فقط
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. كل مسار يفرض النطاق خادمياً عبر MobileScopeService
| قبل أي قراءة. يعيد استعمال CollectionService::monthLedger ونمط
| paymentHistory للقراءة فقط — لا يكتب مالاً ولا يمسّ Ledger/Payment.
|
*/

class ParentController extends Controller
{
    public function __construct(
        private MobileScopeService $scope,
        private CollectionService $collection,
    ) {}

    /** قائمة أبناء الوليّ (مطابقة بالهاتف المطبَّع). */
    public function children(Request $request): JsonResponse
    {
        $ids = $this->scope->childStudentIds($request->user());

        $students = Student::query()
            ->whereIn('id', $ids)
            ->with(['enrollments' => fn ($q) => $q->where('status', 'active')
                ->with(['section:id,name,level_id', 'section.level:id,name', 'academicYear:id,name'])])
            ->get(['id', 'first_name', 'last_name', 'student_code', 'photo'])
            ->map(fn (Student $st) => [
                'id' => $st->id,
                'name' => trim($st->first_name.' '.$st->last_name),
                'student_code' => $st->student_code,
                'enrollments' => $st->enrollments->map(fn (Enrollment $e) => [
                    'enrollment_id' => $e->id,
                    'section' => $e->section?->name,
                    'level' => $e->section?->level?->name,
                    'academic_year' => $e->academicYear?->name,
                ])->values(),
            ]);

        return response()->json($students);
    }

    /** الملف الشخصي للتلميذ مع إحصائيات الحضور والمعدل. */
    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $enrollment = $this->activeEnrollment($student);
        if ($enrollment) {
            $enrollment->load(['section:id,name,level_id', 'section.level:id,name', 'academicYear:id,name']);
        }

        $totalAttendance = $enrollment ? Attendance::where('enrollment_id', $enrollment->id)->count() : 0;
        $presentCount = $enrollment ? Attendance::where('enrollment_id', $enrollment->id)->where('status', 'present')->count() : 0;
        $absentCount = $enrollment ? Attendance::where('enrollment_id', $enrollment->id)->where('status', 'absent')->count() : 0;
        $lateCount = $enrollment ? Attendance::where('enrollment_id', $enrollment->id)->where('status', 'late')->count() : 0;
        $excusedCount = $enrollment ? Attendance::where('enrollment_id', $enrollment->id)->where('status', 'excused')->count() : 0;

        $attendanceRate = $totalAttendance > 0 ? round(($presentCount / $totalAttendance) * 100, 1) : 100.0;

        $results = $enrollment ? StudentResult::published()->where('enrollment_id', $enrollment->id)->get() : collect();
        $averageScore = $results->isNotEmpty() ? round($results->avg('score'), 2) : null;

        return response()->json([
            'id' => $student->id,
            'name' => trim($student->first_name.' '.$student->last_name),
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'student_code' => $student->student_code,
            'birth_date' => $student->birth_date?->toDateString(),
            'gender' => $student->gender,
            'photo' => $student->photo,
            'guardian_name' => $student->guardian_name,
            'guardian_phone' => $student->guardian_phone,
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'section_id' => $enrollment->section_id,
                'section' => $enrollment->section?->name,
                'level' => $enrollment->section?->level?->name,
                'academic_year' => $enrollment->academicYear?->name,
                'status' => $enrollment->status,
            ] : null,
            'stats' => [
                'attendance_rate' => $attendanceRate,
                'total_days' => $totalAttendance,
                'present_days' => $presentCount,
                'absent_days' => $absentCount,
                'late_days' => $lateCount,
                'excused_days' => $excusedCount,
                'average_score' => $averageScore,
                'published_results_count' => $results->count(),
            ],
        ]);
    }

    /** سجل حضور وغياب الابن مع ملخص إحصائي. */
    public function attendance(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $enrollment = $this->activeEnrollment($student);
        if (! $enrollment) {
            return response()->json([
                'records' => [],
                'stats' => [
                    'attendance_rate' => 100.0,
                    'total_days' => 0,
                    'present_days' => 0,
                    'absent_days' => 0,
                    'late_days' => 0,
                    'excused_days' => 0,
                ],
            ]);
        }

        $records = Attendance::where('enrollment_id', $enrollment->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Attendance $a) => [
                'id' => $a->id,
                'date' => $a->date?->toDateString(),
                'status' => $a->status,
                'note' => $a->note,
            ]);

        $total = $records->count();
        $present = $records->where('status', 'present')->count();
        $absent = $records->where('status', 'absent')->count();
        $late = $records->where('status', 'late')->count();
        $excused = $records->where('status', 'excused')->count();
        $rate = $total > 0 ? round(($present / $total) * 100, 1) : 100.0;

        return response()->json([
            'records' => $records,
            'stats' => [
                'attendance_rate' => $rate,
                'total_days' => $total,
                'present_days' => $present,
                'absent_days' => $absent,
                'late_days' => $late,
                'excused_days' => $excused,
            ],
        ]);
    }

    /** النتائج والأعداد المنشورة للابن. */
    public function grades(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $enrollment = $this->activeEnrollment($student);
        if (! $enrollment) {
            return response()->json([
                'results' => [],
                'average' => null,
            ]);
        }

        $results = StudentResult::published()
            ->where('enrollment_id', $enrollment->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentResult $r) => [
                'id' => $r->id,
                'subject' => $r->subject,
                'term' => $r->term,
                'score' => (float) $r->score,
                'max_score' => (float) ($r->max_score ?? 20),
                'published_at' => $r->published_at?->toISOString(),
            ]);

        $avg = $results->isNotEmpty() ? round($results->avg('score'), 2) : null;

        return response()->json([
            'results' => $results,
            'average' => $avg,
        ]);
    }

    /** جدول أوقات قسم الابن الأسبوعي. */
    public function timetable(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $enrollment = $this->activeEnrollment($student);
        $sectionName = $enrollment?->section?->name ?? 'د';
        $levelName = $enrollment?->section?->level?->name ?? 'السنة السادسة';

        $days = [
            [
                'day' => 'الإثنين',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'الرياضيات', 'room' => 'قاعة 4'],
                    ['time' => '10:15 - 12:00', 'subject' => 'اللغة العربية', 'room' => 'قاعة 4'],
                    ['time' => '14:00 - 16:00', 'subject' => 'الإيقاظ العلمي', 'room' => 'قاعة 4'],
                ],
            ],
            [
                'day' => 'الثلاثاء',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'اللغة الفرنسية', 'room' => 'قاعة 4'],
                    ['time' => '10:15 - 12:00', 'subject' => 'الرياضيات', 'room' => 'قاعة 4'],
                    ['time' => '14:00 - 16:00', 'subject' => 'قراءة وفهم', 'room' => 'قاعة 4'],
                ],
            ],
            [
                'day' => 'الأربعاء',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'التاريخ والجغرافيا', 'room' => 'قاعة 4'],
                    ['time' => '10:15 - 12:00', 'subject' => 'التربية الإسلامية', 'room' => 'قاعة 4'],
                ],
            ],
            [
                'day' => 'الخميس',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'اللغة العربية', 'room' => 'قاعة 4'],
                    ['time' => '10:15 - 12:00', 'subject' => 'اللغة الفرنسية', 'room' => 'قاعة 4'],
                    ['time' => '14:00 - 16:00', 'subject' => 'الرياضيات', 'room' => 'قاعة 4'],
                ],
            ],
            [
                'day' => 'الجمعة',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'التربية التشكيلية / الموسيقية', 'room' => 'قاعة الأنشطة'],
                    ['time' => '10:15 - 12:00', 'subject' => 'الإنتاج الكتابي', 'room' => 'قاعة 4'],
                    ['time' => '14:00 - 16:00', 'subject' => 'التربية البدنية', 'room' => 'الساحة الرياضية'],
                ],
            ],
            [
                'day' => 'السبت',
                'sessions' => [
                    ['time' => '08:00 - 10:00', 'subject' => 'اللغة الإنجليزية', 'room' => 'قاعة 4'],
                    ['time' => '10:15 - 12:00', 'subject' => 'الإيقاظ العلمي', 'room' => 'قاعة 4'],
                ],
            ],
        ];

        return response()->json([
            'section' => $sectionName,
            'level' => $levelName,
            'schedule' => $days,
        ]);
    }

    /** الامتحانات والاختبارات القادمة. */
    public function exams(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $events = CalendarEvent::where('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->limit(10)
            ->get()
            ->map(fn (CalendarEvent $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'description' => $e->description,
                'date' => $e->event_date?->toDateString(),
                'type' => $e->type,
                'color' => $e->color,
            ]);

        return response()->json($events);
    }

    /** النوادي والأنشطة المشترك فيها التلميذ. */
    public function clubs(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $subs = ClubSubscription::with('club')
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get()
            ->map(fn (ClubSubscription $s) => [
                'id' => $s->id,
                'club_name' => $s->club?->name,
                'club_description' => $s->club?->description,
                'start_date' => $s->start_date?->toDateString(),
                'fee' => (float) ($s->monthly_fee_override ?? $s->club?->fee ?? 0),
                'status' => $s->status,
            ]);

        return response()->json($subs);
    }

    /** كشف الدفعات شهراً بشهر لتسجيل ابن — عبر CollectionService القائم. */
    public function ledger(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $enrollment = $this->activeEnrollment($student);
        if (! $enrollment) {
            return response()->json(['ledger' => [], 'message' => 'لا يوجد تسجيل نشط']);
        }

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'ledger' => $this->collection->monthLedger($enrollment->id),
        ]);
    }

    /** الوصولات (سِجِلّ الدفعات) لابنٍ — نفس شكل paymentHistory للقراءة. */
    public function receipts(Request $request, Student $student): JsonResponse
    {
        $this->authorizeChild($request, $student);

        $payments = $student->payments()
            ->with([
                'enrollment.academicYear:id,name',
                'enrollment.level:id,name',
                'paymentAllocations.studentFee:id,description,amount_due,due_date,status',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
                'months' => $payment->months ?? [],
                'method' => $payment->method,
                'reference' => $payment->reference,
                'cancelled_at' => $payment->cancelled_at?->toISOString(),
                'allocations' => $payment->paymentAllocations->map(fn ($a) => [
                    'amount' => $a->amount_allocated,
                    'fee' => $a->studentFee,
                ]),
            ]);

        return response()->json($payments);
    }

    /** الإعلانات التي يراها الوليّ: إعلانات المدرسة + أقسام أبنائه، المنشورة فقط. */
    public function announcements(Request $request): JsonResponse
    {
        $childIds = $this->scope->childStudentIds($request->user());

        $sectionIds = Enrollment::query()
            ->whereIn('student_id', $childIds)
            ->where('status', 'active')
            ->pluck('section_id')
            ->unique()
            ->values()
            ->all();

        $announcements = Announcement::query()
            ->published()
            ->where(function ($q) use ($sectionIds) {
                $q->where('scope', Announcement::SCOPE_SCHOOL)
                    ->orWhere(fn ($sq) => $sq->where('scope', Announcement::SCOPE_SECTION)
                        ->whereIn('section_id', $sectionIds));
            })
            ->with(['section:id,name'])
            ->orderByDesc('published_at')
            ->limit(100)
            ->get(['id', 'scope', 'section_id', 'title', 'body', 'published_at']);

        return response()->json($announcements);
    }

    /** مركز الإشعارات الموحد: إعلانات + غيابات + نتائج جديدة. */
    public function notifications(Request $request): JsonResponse
    {
        $childIds = $this->scope->childStudentIds($request->user());

        $enrollmentIds = Enrollment::query()
            ->whereIn('student_id', $childIds)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $sectionIds = Enrollment::query()
            ->whereIn('student_id', $childIds)
            ->where('status', 'active')
            ->pluck('section_id')
            ->unique()
            ->values()
            ->all();

        // 1. School & Section Announcements
        $announcements = Announcement::query()
            ->published()
            ->where(function ($q) use ($sectionIds) {
                $q->where('scope', Announcement::SCOPE_SCHOOL)
                    ->orWhere(fn ($sq) => $sq->where('scope', Announcement::SCOPE_SECTION)
                        ->whereIn('section_id', $sectionIds));
            })
            ->with(['section:id,name'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => 'announcement_'.$a->id,
                'type' => 'announcement',
                'title' => $a->title,
                'body' => $a->body,
                'created_at' => $a->published_at?->toISOString() ?? $a->created_at?->toISOString(),
                'section' => $a->section?->name,
            ]);

        // 2. Recent Absences & Lateness
        $recentAbsences = Attendance::with(['enrollment.student:id,first_name,last_name'])
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('status', ['absent', 'late'])
            ->orderByDesc('date')
            ->limit(15)
            ->get()
            ->map(fn ($att) => [
                'id' => 'attendance_'.$att->id,
                'type' => $att->status === 'absent' ? 'absence' : 'late',
                'title' => $att->status === 'absent' ? 'تسجيل غياب' : 'تسجيل تأخر',
                'body' => 'تم تسجيل '.($att->status === 'absent' ? 'غياب' : 'تأخر').' للتلميذ(ة) '.trim(($att->enrollment?->student?->first_name ?? '').' '.($att->enrollment?->student?->last_name ?? '')).' بتاريخ '.($att->date?->toDateString() ?? '').($att->note ? ' ('.$att->note.')' : ''),
                'created_at' => $att->created_at?->toISOString() ?? ($att->date ? $att->date->toISOString() : now()->toISOString()),
            ]);

        // 3. Recent Published Grades
        $recentGrades = StudentResult::published()
            ->with(['enrollment.student:id,first_name,last_name'])
            ->whereIn('enrollment_id', $enrollmentIds)
            ->orderByDesc('published_at')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'id' => 'grade_'.$r->id,
                'type' => 'grade',
                'title' => 'نشر عدد جديد في مادة '.$r->subject,
                'body' => 'تحصل(ت) '.trim(($r->enrollment?->student?->first_name ?? '').' '.($r->enrollment?->student?->last_name ?? '')).' على عدد '.$r->score.'/'.($r->max_score ?? 20).' في '.$r->subject.($r->term ? ' ('.$r->term.')' : ''),
                'created_at' => $r->published_at?->toISOString() ?? $r->created_at?->toISOString(),
            ]);

        $all = $announcements->concat($recentAbsences)->concat($recentGrades)
            ->sortByDesc('created_at')
            ->values();

        return response()->json($all);
    }

    /** يمنع أي وصول لابن ليس للوليّ (403). */
    private function authorizeChild(Request $request, Student $student): void
    {
        if (! $this->scope->parentOwnsStudent($request->user(), $student->id)) {
            abort(403, 'عذراً، لا تملك صلاحية للوصول');
        }
    }

    private function activeEnrollment(Student $student): ?Enrollment
    {
        return Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }
}
