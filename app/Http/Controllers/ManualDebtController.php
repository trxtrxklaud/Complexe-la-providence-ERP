<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\ManualStudentDebt;
use App\Models\StudentFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * الديون القديمة المدخلة يدوياً (بيانات خارجية بلا رسم سابق في النظام).
 *
 * إدخال الدَّين لا يحرّك مالاً إطلاقاً — لا أثر له في الدفتر النقدي. المال
 * يتحرّك فقط يوم تحصيل الدَّين عبر مسار متخلّدات السنوات السابقة نفسه
 * (prior_allocations.manual_student_debt_id في عملية الاستخلاص).
 *
 * كل دَين ينشئ رسمَين جسراً تحت تسجيل التلميذ في آخر سنة دراسية سابقة:
 * توزيعات الدفع تشير حتماً إلى student_fee، ويصنّف الدفتر القبض دَين سنة
 * سابقة من اختلاف سنة التسجيل عن سنة الدفعة.
 */
class ManualDebtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = ManualStudentDebt::with([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('debt_type'), fn ($q) => $q->where('debt_type', $request->input('debt_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        $items->through(function (ManualStudentDebt $debt) {
            $debt->setAttribute('collected_amount', $debt->collected());
            $debt->setAttribute('outstanding_amount', $debt->outstanding());

            return $debt;
        });

        return response()->json($items);
    }

    /**
     * إدخال دَين قديم يدوياً — بدون أي أثر نقدي.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'debt_type' => ['required', 'string', 'in:'.implode(',', ManualStudentDebt::DEBT_TYPES)],
            'description' => ['required', 'string', 'max:255'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $yearId = $data['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id');

        if (! $yearId) {
            return response()->json(['message' => 'لا توجد سنة دراسية نشطة؛ حدِّد السنة الدراسية'], 422);
        }

        $data['academic_year_id'] = (int) $yearId;
        $data['created_by'] = $request->user()?->id;
        $data['status'] = ManualStudentDebt::STATUS_PENDING;

        try {
            $debt = DB::transaction(function () use ($data) {
                $targetYear = AcademicYear::findOrFail($data['academic_year_id']);

                // آخر تسجيل سابق (تنتهي سنته قبل بداية سنة النقل) — جسر الدَّين.
                $priorEnrollment = Enrollment::where('student_id', $data['student_id'])
                    ->with('academicYear')
                    ->get()
                    ->filter(fn (Enrollment $enrollment) => $enrollment->academicYear?->end_date < $targetYear->start_date)
                    ->sortByDesc(fn (Enrollment $enrollment) => $enrollment->academicYear->end_date)
                    ->first();

                if (! $priorEnrollment) {
                    throw new RuntimeException(
                        'لا يوجد تسجيل سابق لهذا التلميذ لنقل الدَّين إليه؛ استعمل متخلّدات سنوات سابقة عبر إقفال السنة'
                    );
                }

                $bridgeFee = StudentFee::create([
                    'enrollment_id' => $priorEnrollment->id,
                    'fee_plan_id' => null,
                    'fee_type_id' => null,
                    'club_monthly_fee_id' => null,
                    'description' => 'دَين قديم: '.$data['description'],
                    'amount_due' => number_format((float) $data['original_amount'], 2, '.', ''),
                    'due_date' => $priorEnrollment->academicYear->start_date,
                    'status' => 'pending',
                ]);

                return ManualStudentDebt::create([
                    ...$data,
                    'source_student_fee_id' => $bridgeFee->id,
                ]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($debt->load([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'sourceStudentFee:id,enrollment_id,description,amount_due,due_date,status',
        ]), 201);
    }

    public function show(ManualStudentDebt $debt): JsonResponse
    {
        $debt->load([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'sourceStudentFee:id,enrollment_id,description,amount_due,due_date,status',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]);

        $debt->setAttribute('collected_amount', $debt->collected());
        $debt->setAttribute('outstanding_amount', $debt->outstanding());

        return response()->json($debt);
    }

    /**
     * إلغاء دَين مُدخل خطأً — لا حذف نهائي حتّى يبقى مسار التدقيق مقروءاً.
     * يُمنع الإلغاء بعد تحصيل أي جزء: إلغاء الدَّين بلا ردّ ماله يبخّر المال.
     */
    public function cancel(Request $request, ManualStudentDebt $debt): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($debt->isCancelled()) {
            return response()->json(['message' => 'هذا الدَّين ملغى مسبقاً'], 422);
        }

        $collected = $debt->collected();
        if ($collected > 0) {
            return response()->json([
                'message' => 'هذا الدَّين حُصّل منه '.number_format($collected, 2, '.', '').'؛ لا يمكن إلغاؤه، ألغِ الدفعات المرتبطة به أوّلاً',
            ], 422);
        }

        DB::transaction(function () use ($debt, $data, $request) {
            $userId = $request->user()?->id;

            $debt->update([
                'status' => ManualStudentDebt::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $data['reason'],
            ]);

            // الرسم الجسر بلا أي تحصيل → يُمحى: لا مال تحته ولا توزيع يشير إليه.
            $debt->sourceStudentFee?->delete();
        });

        return response()->json($debt->fresh()->load([
            'student:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }
}
