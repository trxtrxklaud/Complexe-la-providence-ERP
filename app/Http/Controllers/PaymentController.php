<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\LedgerService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly LedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);

        $payments = Payment::with([
            'student:id,first_name,last_name,student_code',
            'enrollment:id,academic_year_id,level_id,status',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
            'paymentAllocations.studentFee:id,description,amount_due,due_date,status',
        ])
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->enrollment_id, fn ($q) => $q->where('enrollment_id', $request->integer('enrollment_id')))
            ->when($request->method, fn ($q) => $q->where('method', $request->input('method')))
            ->when($request->date_from, fn ($q) => $q->whereDate('payment_date', '>=', $request->input('date_from')))
            ->when($request->date_to, fn ($q) => $q->whereDate('payment_date', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            // صفحة Historique: إرجاع الوصولات الملغاة فقط عند ?cancelled=1
            ->when($request->boolean('cancelled'), fn ($q) => $q->whereNotNull('cancelled_at'))
            // الملغاة تُرتَّب بتاريخ الإلغاء الأحدث؛ غيرها بتاريخ الدفع.
            ->when(
                $request->boolean('cancelled'),
                fn ($q) => $q->orderByDesc('cancelled_at'),
                fn ($q) => $q->latest('payment_date')
            )
            ->paginate($perPage);

        return response()->json($payments);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            // مفتاح منع التكرار: يُفضَّل ترويسة Idempotency-Key ثم حقل الطلب.
            $data['idempotency_key'] = $request->header('Idempotency-Key')
                ?: ($data['idempotency_key'] ?? null);

            $payment = $this->paymentService->recordPayment(
                $data,
                auth()->id()
            );

            return response()->json(
                $payment->load([
                    'paymentAllocations.studentFee',
                    'student:id,first_name,last_name,student_code',
                    'createdBy:id,first_name,last_name',
                ]),
                201
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Payment recording failed.'], 500);
        }
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json(
            $payment->load([
                'student:id,first_name,last_name,student_code',
                'enrollment.academicYear:id,name',
                'enrollment.level:id,name',
                'createdBy:id,first_name,last_name',
                'cancelledBy:id,first_name,last_name',
                'paymentAllocations.studentFee',
            ])
        );
    }

    /**
     * إلغاء موثّق بدل الحذف النهائي: يبقى سجل الدفعة (للقسم «الوصولات الملغاة») مع سبب الإلغاء
     * والمنفّذ وتاريخه، وتُلغى معها أسطر الدفتر النقدي حتى لا تظهر في أي تقرير مالي.
     *
     * «مسح كلي للعملية»: الرسوم الشهرية التي أنشأها الاستخلاص نفسه على النحو
     * (student_fees.fee_plan_id = null، غير مرتبطة بنادٍ، لا توزيعات من دفعات أخرى،
     * غير محالة إلى أرصدة افتتاحية، وبلا تنازلات) تُحذف نهائياً — فيعود الشهر مفتوحاً
     * ويختفي المبلغ من المتخلّد. أما الرسوم القائمة أصلاً (دَين سابق، نادي، رسم مخطط،
     * أو رسم تخصّه دفعات أخرى) فتبقى وتُعاد حالتها: تعود غير مدفوعة تلقائياً.
     */
    public function cancel(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($payment->cancelled_at) {
            return response()->json(['message' => 'هذه الدفعة ملغاة مسبقاً'], 422);
        }

        DB::transaction(function () use ($payment, $data, $request) {
            $feeIds = $payment->paymentAllocations()->pluck('student_fee_id')->unique()->all();

            $payment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            foreach ($feeIds as $feeId) {
                $fee = StudentFee::find($feeId);
                if (! $fee) {
                    continue;
                }

                // رسوم الاستخلاص المؤقتة فقط (fee_plan_id = null وغير مرتبطة بنادٍ)
                // هي بصمة العملية وتُمحى كلياً، بشرط ألّا تخصّها دفعة أخرى سارية،
                // وألّا تكون محالة إلى رصيد افتتاحي (قيد فرادة يمنع حذفها)،
                // وألّا يكون عليها تنازل. عند الحذف تُحذف توزيعاتها آلياً (cascade).
                $hasOtherActiveAllocations = $fee->paymentAllocations()
                    ->where('payment_id', '!=', $payment->id)
                    ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
                    ->exists();

                $isPriorYearDebt = OpeningBalance::where('source_student_fee_id', $fee->id)->exists();

                if ($fee->fee_plan_id === null
                    && $fee->club_monthly_fee_id === null
                    && ! $hasOtherActiveAllocations
                    && ! $isPriorYearDebt
                    && ! $fee->waivers()->exists()
                ) {
                    $fee->delete();

                    continue;
                }

                // رسم قائم أصلاً → يعود غير مدفوع تلقائياً.
                $this->paymentService->recalculateStudentFeeStatus((int) $feeId);
            }

            // سحب أثر الدفعة من الدفتر النقدي المركزي بنفس السبب والمنفّذ.
            $this->ledger->cancelFor($payment, $request->user()?->id, $data['reason']);
        });

        return response()->json(
            $payment->fresh()->load([
                'createdBy:id,first_name,last_name',
                'cancelledBy:id,first_name,last_name',
                'paymentAllocations.studentFee',
            ])
        );
    }

    public function studentBalance(Student $student): JsonResponse
    {
        $balance = $this->paymentService->getStudentBalance($student->id);

        return response()->json([
            'student_id' => $student->id,
            'balance' => $balance,
        ]);
    }

    public function studentFees(Student $student, Request $request): JsonResponse
    {
        $enrollments = $student->enrollments()
            ->with([
                // التوزيعات من الدفعات غير الملغاة فقط حتى تكون المبالغ المخصّصة دقيقة.
                'studentFees.paymentAllocations' => fn ($q) => $q->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at')),
                // التنازلات السارية فقط: التنازل الملغى يعود دَيناً.
                'studentFees.waivers' => fn ($q) => $q->whereNull('cancelled_at'),
                'studentFees.feePlan:id,frequency',
                'studentFees.feeType:id,name_ar,ledger_category',
                'academicYear:id,name',
                'level:id,name',
            ])
            ->when(
                $request->enrollment_id,
                fn ($q) => $q->where('id', $request->integer('enrollment_id'))
            )
            ->get();

        $result = $enrollments->map(fn ($enrollment) => [
            'enrollment_id' => $enrollment->id,
            'academic_year' => $enrollment->academicYear,
            'level' => $enrollment->level,
            'status' => $enrollment->status,
            'fees' => $enrollment->studentFees->map(function ($fee) {
                $allocated = (float) $fee->paymentAllocations->sum('amount_allocated');
                // المتنازَل عنه ليس دَيناً ولا مدخولاً: يُطرح من المتبقّي ويُعرض مستقلاً.
                $waived = (float) $fee->waivers->sum('amount');

                return [
                    'id' => $fee->id,
                    'description' => $fee->description,
                    'amount_due' => $fee->amount_due,
                    'due_date' => $fee->due_date,
                    'status' => $fee->status,
                    'allocated' => $allocated,
                    'direct_paid' => $fee->directPaidAmount(),
                    'waived' => $waived,
                    'remaining' => max(0, round((float) $fee->amount_due - $allocated - $fee->directPaidAmount() - $waived, 2)),
                    'frequency' => $fee->feePlan?->frequency,
                    'category' => $fee->feeType?->resolveLedgerCategory(),
                ];
            }),
        ]);

        return response()->json($result);
    }
}
