<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
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
