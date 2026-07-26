<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Models\Student;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

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
            ->when($request->student_id,    fn ($q) => $q->where('student_id',    $request->integer('student_id')))
            ->when($request->enrollment_id, fn ($q) => $q->where('enrollment_id', $request->integer('enrollment_id')))
            ->when($request->method,        fn ($q) => $q->where('method',        $request->input('method')))
            ->when($request->date_from,     fn ($q) => $q->whereDate('payment_date', '>=', $request->input('date_from')))
            ->when($request->date_to,       fn ($q) => $q->whereDate('payment_date', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('payment_date')
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
     * إلغاء موثّق بدل الحذف النهائي: يبقى سجل الدفعة وتوزيعاته للمراجعة،
     * مع تسجيل سبب الإلغاء والمنفّذ وتاريخه، وتعود الرسوم غير مدفوعة تلقائياً.
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
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            foreach ($feeIds as $feeId) {
                $this->paymentService->recalculateStudentFeeStatus((int) $feeId);
            }
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
            'balance'    => $balance,
        ]);
    }

    public function studentFees(Student $student, Request $request): JsonResponse
    {
        $enrollments = $student->enrollments()
            ->with([
                // التوزيعات من الدفعات غير الملغاة فقط حتى تكون المبالغ المخصّصة دقيقة.
                'studentFees.paymentAllocations' => fn ($q) =>
                    $q->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at')),
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
            'level'         => $enrollment->level,
            'status'        => $enrollment->status,
            'fees'          => $enrollment->studentFees->map(function ($fee) {
                $allocated = $fee->paymentAllocations->sum('amount_allocated');
                return [
                    'id'          => $fee->id,
                    'description' => $fee->description,
                    'amount_due'  => $fee->amount_due,
                    'due_date'    => $fee->due_date,
                    'status'      => $fee->status,
                    'allocated'   => $allocated,
                    'remaining'   => max(0, $fee->amount_due - $allocated),
                ];
            }),
        ]);

        return response()->json($result);
    }
}
