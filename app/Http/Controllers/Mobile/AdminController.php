<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\ClubSubscription;
use App\Models\Club;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Section;
use App\Models\SectionTeacher;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\StudentResult;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AdminController — قراءة شاملة لبيانات المنصة (الإدارة فقط).
 *
 * الحماية: auth:sanctum + active  (super_role يتجاوز الصلاحيات الدقيقة تلقائياً).
 * جميع العمليات قراءة-فقط عدا إنشاء/تعديل/حذف الأقسام.
 *
 * ملاحظات التوافق مع قاعدة البيانات الحقيقية:
 *  - لا يوجد جدول groups  → تجميع بـ guardian_phone
 *  - لا يوجد جدول timetables → جدول وطني ثابت
 *  - لا يوجد جدول subjects   → المادة حقل نصي في student_results
 *  - لا يوجد جدول notifications → نستعمل announcements
 *  - الكشف المالي: student_fees + payment_allocations (لا نلمس cash_transactions)
 */
class AdminController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    //  1. الإحصاءات
    // ═══════════════════════════════════════════════════════════════════════

    public function stats(): JsonResponse
    {
        $year = AcademicYear::where('is_active', true)->latest('id')->first();

        $activeEnrollments = Enrollment::where('status', 'active')
            ->when($year, fn ($q) => $q->where('academic_year_id', $year->id))
            ->count();

        $totalStudents = Student::count();

        $maleCount = Student::whereHas('enrollments', function ($q) use ($year) {
            $q->where('status', 'active');
            if ($year) $q->where('academic_year_id', $year->id);
        })->where('gender', 'male')->count();

        $femaleCount = Student::whereHas('enrollments', function ($q) use ($year) {
            $q->where('status', 'active');
            if ($year) $q->where('academic_year_id', $year->id);
        })->where('gender', 'female')->count();

        $sectionsCount   = Section::count();
        $levelsCount     = Level::count();
        $teachersCount   = Employee::where('is_active', true)
                               ->whereIn('staff_type', ['monthly_teacher', 'hourly_teacher'])
                               ->count();
        $clubsCount      = Club::where('is_active', true)->count();
        $subscriptions   = ClubSubscription::count();
        $familyCount     = Student::select('guardian_phone')
                               ->distinct()
                               ->whereNotNull('guardian_phone')
                               ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year'        => $year?->name,
                'total_students'       => $totalStudents,
                'active_enrollments'   => $activeEnrollments,
                'total_males'          => $maleCount,
                'total_females'        => $femaleCount,
                'sections_count'       => $sectionsCount,
                'levels_count'         => $levelsCount,
                'active_teachers'      => $teachersCount,
                'active_clubs'         => $clubsCount,
                'club_subscriptions'   => $subscriptions,
                'families_count'       => $familyCount,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  2. المستويات
    // ═══════════════════════════════════════════════════════════════════════

    public function levels(): JsonResponse
    {
        $levels = Level::withCount('sections')->orderBy('order')->get();
        return response()->json(['success' => true, 'data' => $levels]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  3. الأقسام  (CRUD)
    // ═══════════════════════════════════════════════════════════════════════

    public function sections(Request $request): JsonResponse
    {
        $query = Section::with('level')
            ->withCount([
                'enrollments as active_students_count' => fn ($q) => $q->where('status', 'active'),
            ]);

        if ($request->filled('level_id')) {
            $query->where('level_id', $request->integer('level_id'));
        }

        $sections = $query->orderBy('level_id')->orderBy('name')->get();

        // معلمو كل قسم عبر جدول الربط
        $pivots = SectionTeacher::whereIn('section_id', $sections->pluck('id'))
            ->with('employee:id,first_name,last_name')
            ->get()
            ->groupBy('section_id');

        $sections->each(function ($s) use ($pivots) {
            $s->teachers = ($pivots[$s->id] ?? collect())
                ->map(fn ($p) => [
                    'id'        => $p->employee->id,
                    'full_name' => $p->employee->full_name,
                    'subject'   => $p->subject,
                ]);
        });

        return response()->json(['success' => true, 'data' => $sections]);
    }

    public function section(Section $section): JsonResponse
    {
        $section->load('level');
        $section->loadCount([
            'enrollments as active_students_count' => fn ($q) => $q->where('status', 'active'),
        ]);

        $teachers = SectionTeacher::where('section_id', $section->id)
            ->with('employee:id,first_name,last_name,phone,staff_type')
            ->get()
            ->map(fn ($p) => [
                'id'         => $p->employee->id,
                'full_name'  => $p->employee->full_name,
                'phone'      => $p->employee->phone,
                'staff_type' => $p->employee->staff_type,
                'subject'    => $p->subject,
            ]);

        $students = Enrollment::where('section_id', $section->id)
            ->where('status', 'active')
            ->with('student:id,student_code,first_name,last_name,gender,dob,guardian_phone,guardian_first_name,guardian_last_name')
            ->get()
            ->map(fn ($e) => array_merge($e->student->toArray(), ['enrollment_id' => $e->id]));

        return response()->json([
            'success'  => true,
            'data'     => $section,
            'teachers' => $teachers,
            'students' => $students,
        ]);
    }

    public function createSection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level_id' => 'required|exists:levels,id',
            'name'     => 'required|string|max:100',
            'code'     => 'nullable|string|max:20|unique:sections,code',
            'capacity' => 'nullable|integer|min:1|max:100',
        ]);

        $section = Section::create($validated);
        $section->load('level');

        return response()->json(['success' => true, 'data' => $section, 'message' => 'تم إنشاء القسم'], 201);
    }

    public function updateSection(Request $request, Section $section): JsonResponse
    {
        $validated = $request->validate([
            'level_id' => 'sometimes|exists:levels,id',
            'name'     => 'sometimes|string|max:100',
            'code'     => 'sometimes|nullable|string|max:20|unique:sections,code,' . $section->id,
            'capacity' => 'sometimes|nullable|integer|min:1|max:100',
        ]);

        $section->update($validated);
        $section->load('level');

        return response()->json(['success' => true, 'data' => $section, 'message' => 'تم تحديث القسم']);
    }

    public function deleteSection(Section $section): JsonResponse
    {
        $active = Enrollment::where('section_id', $section->id)->where('status', 'active')->count();

        if ($active > 0) {
            return response()->json([
                'success' => false,
                'message' => "لا يمكن حذف قسم يحتوي على {$active} تلميذ(ة) نشط(ة).",
            ], 422);
        }

        $section->delete();
        return response()->json(['success' => true, 'message' => 'تم حذف القسم']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  4. العائلات (مجمَّعة من students.guardian_phone)
    // ═══════════════════════════════════════════════════════════════════════

    public function families(Request $request): JsonResponse
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = min(50, max(10, $request->integer('per_page', 20)));
        $search  = trim($request->string('search', ''));

        $query = Student::select(
                'guardian_phone',
                'guardian_first_name',
                'guardian_last_name',
                'mother_name',
                'guardian_email',
                DB::raw('COUNT(*) as student_count')
            )
            ->groupBy('guardian_phone', 'guardian_first_name', 'guardian_last_name', 'mother_name', 'guardian_email')
            ->orderBy('guardian_last_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('guardian_first_name', 'like', "%{$search}%")
                  ->orWhere('guardian_last_name',  'like', "%{$search}%")
                  ->orWhere('guardian_phone',       'like', "%{$search}%");
            });
        }

        return response()->json(['success' => true, 'data' => $query->paginate($perPage, ['*'], 'page', $page)]);
    }

    /** أبناء عائلة واحدة — البحث بالهاتف */
    public function family(Request $request): JsonResponse
    {
        $phone = $request->string('phone', '');

        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'phone مطلوب'], 422);
        }

        $students = Student::where('guardian_phone', $phone)
            ->with([
                'enrollments' => fn ($q) => $q->where('status', 'active')
                    ->with('section:id,name,code', 'section.level:id,name'),
            ])
            ->get(['id', 'student_code', 'first_name', 'last_name', 'gender', 'dob',
                   'guardian_phone', 'guardian_first_name', 'guardian_last_name', 'status']);

        if ($students->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'لم يُعثر على عائلة بهذا الرقم'], 404);
        }

        $guardian = [
            'guardian_phone'      => $students->first()->guardian_phone,
            'guardian_first_name' => $students->first()->guardian_first_name,
            'guardian_last_name'  => $students->first()->guardian_last_name,
        ];

        return response()->json(['success' => true, 'data' => ['guardian' => $guardian, 'students' => $students]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  5. التلاميذ
    // ═══════════════════════════════════════════════════════════════════════

    public function students(Request $request): JsonResponse
    {
        $page      = max(1, $request->integer('page', 1));
        $perPage   = min(100, max(10, $request->integer('per_page', 20)));
        $search    = trim($request->string('search', ''));
        $sectionId = $request->integer('section_id', 0);

        $query = Student::select(
                'id', 'student_code', 'first_name', 'last_name', 'gender',
                'dob', 'guardian_phone', 'guardian_first_name', 'guardian_last_name',
                'status', 'enrollment_date'
            )
            ->with([
                'enrollments' => fn ($q) => $q->where('status', 'active')
                    ->with('section:id,name,code')
                    ->latest('id')
                    ->limit(1),
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name',        'like', "%{$search}%")
                  ->orWhere('last_name',        'like', "%{$search}%")
                  ->orWhere('student_code',     'like', "%{$search}%")
                  ->orWhere('guardian_phone',   'like', "%{$search}%");
            });
        }

        if ($sectionId > 0) {
            $query->whereHas('enrollments', fn ($q) =>
                $q->where('section_id', $sectionId)->where('status', 'active')
            );
        }

        return response()->json(['success' => true, 'data' => $query->orderBy('last_name')->paginate($perPage, ['*'], 'page', $page)]);
    }

    public function student(Student $student): JsonResponse
    {
        $student->load([
            'enrollments' => fn ($q) => $q->where('status', 'active')
                ->with('section:id,name,code', 'section.level:id,name', 'academicYear:id,name'),
        ]);

        // آخر تسجيل نشط
        $enrollment = $student->enrollments->first();

        // الحضور (آخر 30 يوم)
        $attendance = $enrollment
            ? Attendance::where('enrollment_id', $enrollment->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('date')
                ->get(['date', 'status', 'note'])
            : [];

        // النتائج المنشورة
        $results = $enrollment
            ? StudentResult::where('enrollment_id', $enrollment->id)
                ->published()
                ->orderByDesc('term')
                ->get(['subject', 'term', 'score', 'max_score', 'published_at'])
            : [];

        // الرسوم المدرسية
        $fees = $enrollment
            ? StudentFee::where('enrollment_id', $enrollment->id)
                ->with('feeType:id,name')
                ->get(['id', 'description', 'amount_due', 'direct_paid_amount', 'due_date', 'status', 'fee_type_id'])
                ->map(fn ($f) => [
                    'id'           => $f->id,
                    'description'  => $f->description,
                    'amount_due'   => $f->amount_due,
                    'fee_type'     => $f->feeType?->name,
                    'due_date'     => $f->due_date?->toDateString(),
                    'status'       => $f->status,
                    'outstanding'  => $f->outstanding(),
                ])
            : [];

        // النوادي
        $clubs = $enrollment
            ? ClubSubscription::where('enrollment_id', $enrollment->id)
                ->with('club:id,name,monthly_fee,is_active')
                ->get()
            : [];

        return response()->json([
            'success' => true,
            'data' => array_merge($student->toArray(), [
                'attendance' => $attendance,
                'results'    => $results,
                'fees'       => $fees,
                'clubs'      => $clubs,
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  6. المعلمون
    // ═══════════════════════════════════════════════════════════════════════

    public function teachers(Request $request): JsonResponse
    {
        $search = trim($request->string('search', ''));

        $query = Employee::select(
                'id', 'first_name', 'last_name', 'phone', 'email',
                'job_title', 'staff_type', 'salary_type', 'is_active', 'hire_date'
            )
            ->whereIn('staff_type', ['monthly_teacher', 'hourly_teacher']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone',     'like', "%{$search}%");
            });
        }

        $teachers = $query->orderBy('last_name')->get();

        $pivots = SectionTeacher::whereIn('employee_id', $teachers->pluck('id'))
            ->with('section:id,name,code,level_id', 'section.level:id,name')
            ->get()
            ->groupBy('employee_id');

        $teachers->each(function ($t) use ($pivots) {
            $t->sections = ($pivots[$t->id] ?? collect())->map(fn ($p) => [
                'section_id'   => $p->section_id,
                'section_name' => $p->section?->name,
                'section_code' => $p->section?->code,
                'level_name'   => $p->section?->level?->name,
                'subject'      => $p->subject,
            ]);
        });

        return response()->json(['success' => true, 'data' => $teachers]);
    }

    public function teacher(Employee $employee): JsonResponse
    {
        $sections = SectionTeacher::where('employee_id', $employee->id)
            ->with('section.level')
            ->get();

        // طلاب المعلم عبر أقسامه
        $sectionIds = $sections->pluck('section_id');
        $studentCount = Enrollment::whereIn('section_id', $sectionIds)
            ->where('status', 'active')
            ->count();

        // آخر 30 يوم حضور للمقارنة
        $attendanceSummary = Attendance::whereIn('section_id', $sectionIds)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'success' => true,
            'data' => array_merge($employee->toArray(), [
                'sections'           => $sections->map(fn ($p) => [
                    'section_id'   => $p->section_id,
                    'section_name' => $p->section?->name,
                    'level_name'   => $p->section?->level?->name,
                    'subject'      => $p->subject,
                ]),
                'total_students'     => $studentCount,
                'attendance_last30'  => $attendanceSummary,
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  7. الحضور (Admin View)
    // ═══════════════════════════════════════════════════════════════════════

    public function attendance(Request $request): JsonResponse
    {
        $query = Attendance::with([
            'enrollment.student:id,first_name,last_name,student_code',
            'section:id,name',
        ])->orderByDesc('date');

        if ($request->filled('section_id')) {
            $query->where('section_id', $request->integer('section_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->string('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $page    = max(1, $request->integer('page', 1));
        $perPage = min(200, max(20, $request->integer('per_page', 50)));

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($perPage, ['*'], 'page', $page),
        ]);
    }

    public function studentAttendance(Student $student, Request $request): JsonResponse
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return response()->json(['success' => true, 'data' => [], 'message' => 'لا يوجد تسجيل نشط']);
        }

        $query = Attendance::where('enrollment_id', $enrollment->id)->orderByDesc('date');

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->string('to'));
        }

        $records = $query->get(['date', 'status', 'note']);

        $summary = $records->groupBy('status')->map->count();

        return response()->json([
            'success' => true,
            'data' => [
                'student'    => $student->only(['id', 'first_name', 'last_name', 'student_code']),
                'section'    => $enrollment->section?->only(['id', 'name', 'code']),
                'summary'    => $summary,
                'records'    => $records,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  8. الدرجات / النتائج
    // ═══════════════════════════════════════════════════════════════════════

    public function grades(Request $request): JsonResponse
    {
        $query = StudentResult::with([
            'enrollment.student:id,first_name,last_name,student_code',
            'enrollment.section:id,name',
        ])->orderByDesc('id');

        if ($request->filled('section_id')) {
            $query->whereHas('enrollment', fn ($q) =>
                $q->where('section_id', $request->integer('section_id'))
            );
        }

        if ($request->filled('subject')) {
            $query->where('subject', 'like', '%' . $request->string('subject') . '%');
        }

        if ($request->filled('term')) {
            $query->where('term', $request->string('term'));
        }

        // Admin يرى الكل (منشورة وغير منشورة)
        $page    = max(1, $request->integer('page', 1));
        $perPage = min(200, max(20, $request->integer('per_page', 50)));

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($perPage, ['*'], 'page', $page),
        ]);
    }

    public function studentGrades(Student $student): JsonResponse
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return response()->json(['success' => true, 'data' => [], 'message' => 'لا يوجد تسجيل نشط']);
        }

        $results = StudentResult::where('enrollment_id', $enrollment->id)
            ->orderBy('term')
            ->orderBy('subject')
            ->get();

        // إحصاء بالمادة
        $bySubject = $results->groupBy('subject')->map(fn ($group) => [
            'count'    => $group->count(),
            'avg'      => round($group->avg(fn ($r) => $r->max_score > 0 ? ($r->score / $r->max_score) * 20 : 0), 2),
            'max_score' => $group->first()->max_score,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'student'     => $student->only(['id', 'first_name', 'last_name', 'student_code']),
                'section'     => $enrollment->section?->only(['id', 'name']),
                'results'     => $results,
                'by_subject'  => $bySubject,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  9. الكشف المالي (student_fees — قراءة فقط، لا نلمس cash_transactions)
    // ═══════════════════════════════════════════════════════════════════════

    public function ledgers(Request $request): JsonResponse
    {
        $query = StudentFee::with([
            'enrollment.student:id,first_name,last_name,student_code',
            'enrollment.section:id,name',
            'feeType:id,name',
        ])->orderByDesc('id');

        if ($request->filled('section_id')) {
            $query->whereHas('enrollment', fn ($q) =>
                $q->where('section_id', $request->integer('section_id'))
            );
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $page    = max(1, $request->integer('page', 1));
        $perPage = min(100, max(20, $request->integer('per_page', 50)));

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($perPage, ['*'], 'page', $page),
        ]);
    }

    public function studentLedger(Student $student): JsonResponse
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return response()->json(['success' => true, 'data' => [], 'message' => 'لا يوجد تسجيل نشط']);
        }

        $fees = StudentFee::where('enrollment_id', $enrollment->id)
            ->with('feeType:id,name')
            ->get()
            ->map(fn ($f) => [
                'id'          => $f->id,
                'description' => $f->description,
                'fee_type'    => $f->feeType?->name,
                'amount_due'  => (float) $f->amount_due,
                'outstanding' => $f->outstanding(),
                'due_date'    => $f->due_date?->toDateString(),
                'status'      => $f->status,
            ]);

        $totalDue     = $fees->sum('amount_due');
        $totalOutstanding = $fees->sum('outstanding');

        return response()->json([
            'success' => true,
            'data' => [
                'student'           => $student->only(['id', 'first_name', 'last_name', 'student_code']),
                'section'           => $enrollment->section?->only(['id', 'name']),
                'fees'              => $fees,
                'total_due'         => round($totalDue, 2),
                'total_outstanding' => round($totalOutstanding, 2),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  10. النوادي
    // ═══════════════════════════════════════════════════════════════════════

    public function clubs(): JsonResponse
    {
        $clubs = Club::withCount('subscriptions')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $clubs]);
    }

    public function club(Club $club): JsonResponse
    {
        $club->loadCount('subscriptions');
        $subscriptions = ClubSubscription::where('club_id', $club->id)
            ->with('enrollment.student:id,first_name,last_name,student_code')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $club,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function clubSubscriptions(Request $request): JsonResponse
    {
        $query = ClubSubscription::with([
            'club:id,name,monthly_fee',
            'enrollment.student:id,first_name,last_name,student_code',
            'enrollment.section:id,name',
        ]);

        if ($request->filled('club_id')) {
            $query->where('club_id', $request->integer('club_id'));
        }

        $page    = max(1, $request->integer('page', 1));
        $perPage = min(100, max(20, $request->integer('per_page', 50)));

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($perPage, ['*'], 'page', $page),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  11. الإشعارات (announcements — لا يوجد جدول notifications)
    // ═══════════════════════════════════════════════════════════════════════

    public function notifications(Request $request): JsonResponse
    {
        $query = Announcement::with('section:id,name', 'author:id,name')
            ->published()
            ->orderByDesc('published_at');

        if ($request->filled('scope')) {
            $query->where('scope', $request->string('scope'));
        }

        $limit = min(200, max(10, $request->integer('limit', 50)));

        return response()->json([
            'success' => true,
            'data'    => $query->limit($limit)->get(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  12. الجدول الزمني (ثابت — لا يوجد جدول timetables في DB)
    // ═══════════════════════════════════════════════════════════════════════

    public function timetable(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'days'  => ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'],
                'slots' => [
                    ['label' => '08:00 – 10:00'],
                    ['label' => '10:00 – 12:00'],
                    ['label' => '14:00 – 16:00'],
                    ['label' => '16:00 – 18:00'],
                ],
                'note' => 'الجدول الوطني الافتراضي للمدارس الابتدائية التونسية. يمكن تخصيصه مستقبلاً.',
            ],
        ]);
    }

    public function sectionTimetable(Section $section): JsonResponse
    {
        return response()->json([
            'success' => true,
            'section' => $section->load('level'),
            'data' => [
                'days'  => ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'],
                'slots' => [
                    ['label' => '08:00 – 10:00'],
                    ['label' => '10:00 – 12:00'],
                    ['label' => '14:00 – 16:00'],
                    ['label' => '16:00 – 18:00'],
                ],
            ],
        ]);
    }
}
