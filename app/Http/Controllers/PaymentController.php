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
            'paymentAllocations.studentFee:id,description,amount_due,due_date,status',
        ])
            ->when($request->student_id,    fn ($q) => $q->where('student_id',    $request->integer('student_id')))
            ->when($request->enrollment_id, fn ($q) => $q->where('enrollment_id', $request->integer('enrollment_id')))
            ->when($request->method,        fn ($q) => $q->where('method',        $request->input('method')))
            ->when($request->date_from,     fn ($q) => $q->whereDate('payment_date', '>=', $request->input('date_from')))
            ->when($request->date_to,       fn ($q) => $q->whereDate('payment_date', '<=', $request->input('date_to')))
            ->latest('payment_date')
            ->paginate($perPage);

        return response()->json($payments);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->paymentService->recordPayment(
                $request->validated(),
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
                'paymentAllocations.studentFee',
            ])
        );
    }

    public function destroy(Payment $payment): JsonResponse
    {
        DB::transaction(function () use ($payment) {
            $payment->paymentAllocations()->delete();
            $payment->delete();
        });

        return response()->json(null, 204);
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
                'studentFees.paymentAllocations',
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
