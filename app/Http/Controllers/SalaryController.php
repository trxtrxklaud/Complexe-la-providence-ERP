<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Salary;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalaryController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $q = Salary::with([
            'employee:id,first_name,last_name',
            'academicYear:id,name',
            'cancelledBy:id,first_name,last_name',
            'settledAdvances:id,settled_by_salary_id,amount,advance_date,reason',
        ])->latest('paid_at')->latest('id');

        if ($request->filled('academic_year_id')) {
            $q->where('academic_year_id', $request->integer('academic_year_id'));
        }
        if ($request->filled('employee_id')) {
            $q->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->boolean('exclude_cancelled')) {
            $q->whereNull('cancelled_at');
        }

        return response()->json($q->paginate(min($request->integer('per_page', 20), 100)));
    }

    /**
     * خلاص راتب مع خصم ما على الإطار من تسبقات وأقساط سلف، في معاملة واحدة.
     *
     * الفرق بين النوعين ليس تسمية بل سلوك محاسبي مختلف:
     *
     *   التسبقة (advance) — advance_ids
     *     تُخصم كاملة من راتب الشهر نفسه. الإطار طلب 100 من 500، فيقبض 400.
     *     تُخلَّص دفعة واحدة وتُختم بـ settled_by_salary_id.
     *
     *   السلفة (loan) — loan_deductions
     *     دَين يُردّ على مهل، فيُخصم منه قسط بمبلغ يختاره القابض لا المتبقّي كلّه.
     *     كل قسط يُسجَّل ردّاً مؤرّخاً بطريقة salary_deduction مربوطاً بـ salary_id.
     *
     * لماذا الربط بـ salary_id ضروري: قبله كان بالإمكان تسجيل ردّ بطريقة
     * «خصم من الراتب» دون وجود راتب أصلاً، فيُطفأ الدَّين بلا أن ينقص راتب
     * ولا أن يدخل الصندوق مليم — أي أن المال يتبخّر من الدفاتر. والآن الردّ
     * لا يولد إلّا من راتب حقيقي، وإلغاء ذلك الراتب يُلغيه معه.
     *
     * الدفتر النقدي يُسقِط الصافي وحده. مبلغ التسبقة خرج من الدرج يوم منحها
     * وسُجّل هناك، وقسط السلفة لم يخرج اليوم أصلاً؛ فإسقاط الخام كان سيُخرج
     * المال مرّتين.
     *
     * يُقبَل amount وحده للتوافق مع النداءات القديمة: يُعامَل خاماً بلا خصم.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'      => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'gross_amount'     => ['nullable', 'numeric', 'min:0.01'],
            'amount'           => ['nullable', 'numeric', 'min:0.01'],
            'advance_ids'      => ['nullable', 'array'],
            'advance_ids.*'    => ['integer', 'exists:employee_advances,id'],

            // أقساط السلف: لكل سلفة مبلغ مستقلّ يختاره القابض.
            'loan_deductions'          => ['nullable', 'array'],
            'loan_deductions.*.id'     => ['required', 'integer', 'exists:employee_advances,id'],
            'loan_deductions.*.amount' => ['required', 'numeric', 'min:0.01'],

            'period_from'      => ['required', 'date'],
            'period_to'        => ['required', 'date', 'after_or_equal:period_from'],
            'paid_at'          => ['nullable', 'date'],
            'method'           => ['nullable', 'string', 'max:50'],
            'reference'        => ['nullable', 'string', 'max:100'],
            'notes'            => ['nullable', 'string'],
        ]);

        $gross = (float) ($data['gross_amount'] ?? $data['amount'] ?? 0);

        if ($gross <= 0) {
            return response()->json(['message' => 'الراتب الخام مطلوب'], 422);
        }

        $advanceIds = array_values(array_unique($data['advance_ids'] ?? []));

        $loanRows = collect($data['loan_deductions'] ?? [])
            ->map(fn (array $row) => [
                'id'     => (int) $row['id'],
                'amount' => round((float) $row['amount'], 2),
            ])
            ->values();

        if ($loanRows->pluck('id')->duplicates()->isNotEmpty()) {
            return response()->json([
                'message' => 'لا يمكن خصم قسطَين من نفس السلفة في راتب واحد؛ اجمعهما في مبلغ واحد',
            ], 422);
        }

        $userId = $request->user()?->id;

        try {
            $salary = DB::transaction(function () use ($data, $gross, $advanceIds, $loanRows, $userId) {
                $advances  = collect();
                $loans     = collect();
                $deduction = 0.0;

                if ($advanceIds !== []) {
                    // القفل يمنع خصم نفس التسبقة مرّتين من راتبَين متزامنَين.
                    $advances = EmployeeAdvance::whereIn('id', $advanceIds)
                        ->where('employee_id', $data['employee_id'])
                        ->where('type', EmployeeAdvance::TYPE_ADVANCE)
                        ->whereNull('cancelled_at')
                        ->lockForUpdate()
                        ->get();

                    if ($advances->count() !== count($advanceIds)) {
                        throw new RuntimeException('بعض التسبقات المختارة غير موجودة، أو ملغاة، أو لا تخصّ هذا الإطار');
                    }

                    foreach ($advances as $advance) {
                        if ($advance->settled_by_salary_id !== null) {
                            throw new RuntimeException('التسبقة رقم ' . $advance->id . ' مخصومة من راتب آخر');
                        }

                        $remaining = round((float) $advance->amount - (float) $advance->settled_amount, 2);

                        if ($remaining <= 0) {
                            throw new RuntimeException('التسبقة رقم ' . $advance->id . ' مخلّصة مسبقاً');
                        }

                        $deduction += $remaining;
                    }
                }

                if ($loanRows->isNotEmpty()) {
                    $loans = EmployeeAdvance::whereIn('id', $loanRows->pluck('id')->all())
                        ->where('employee_id', $data['employee_id'])
                        ->where('type', EmployeeAdvance::TYPE_LOAN)
                        ->whereNull('cancelled_at')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if ($loans->count() !== $loanRows->count()) {
                        throw new RuntimeException('بعض السلف المختارة غير موجودة، أو ملغاة، أو لا تخصّ هذا الإطار');
                    }

                    foreach ($loanRows as $row) {
                        $loan = $loans[$row['id']];

                        // المتبقّي يُشتقّ من الردّيات القائمة لا من settled_amount المخزّن،
                        // فالمخزّن قد يكون قديماً إن أُلغي ردّ في نفس اللحظة.
                        $repaid    = (float) $loan->repayments()->whereNull('cancelled_at')->sum('amount');
                        $remaining = round((float) $loan->amount - $repaid, 2);

                        if ($remaining <= 0) {
                            throw new RuntimeException('السلفة رقم ' . $loan->id . ' مخلّصة بالكامل');
                        }

                        if ($row['amount'] > $remaining) {
                            throw new RuntimeException(
                                'قسط السلفة رقم ' . $loan->id . ' (' . number_format($row['amount'], 2, '.', '') . ') يتجاوز المتبقّي منها (' . number_format($remaining, 2, '.', '') . ')'
                            );
                        }

                        $deduction += $row['amount'];
                    }
                }

                $deduction = round($deduction, 2);
                $net       = round($gross - $deduction, 2);

                if ($net < 0) {
                    throw new RuntimeException(
                        'مجموع الخصومات (' . number_format($deduction, 2, '.', '') . ') يتجاوز الراتب الخام (' . number_format($gross, 2, '.', '') . ')'
                    );
                }

                $salary = Salary::create([
                    'employee_id'       => $data['employee_id'],
                    'academic_year_id'  => $data['academic_year_id'],
                    'gross_amount'      => number_format($gross, 2, '.', ''),
                    'advance_deduction' => number_format($deduction, 2, '.', ''),
                    'amount'            => number_format($net, 2, '.', ''),
                    'period_from'       => $data['period_from'],
                    'period_to'         => $data['period_to'],
                    'paid_at'           => $data['paid_at']   ?? null,
                    'method'            => $data['method']    ?? null,
                    'reference'         => $data['reference'] ?? null,
                    'notes'             => $data['notes']     ?? null,
                    'created_by'        => $userId,
                ]);

                foreach ($advances as $advance) {
                    $advance->update([
                        'settled_amount'       => $advance->amount,
                        'status'               => EmployeeAdvance::STATUS_SETTLED,
                        'settled_by_salary_id' => $salary->id,
                    ]);
                }

                $repaidAt = $data['paid_at'] ?? now()->toDateString();

                foreach ($loanRows as $row) {
                    $loan = $loans[$row['id']];

                    EmployeeAdvanceRepayment::create([
                        'employee_advance_id' => $loan->id,
                        'employee_id'         => $loan->employee_id,
                        // السلف القديمة قد تحمل سنة فارغة؛ سنة الراتب تسدّ الفراغ
                        // فلا يسقط القسط خارج كل التقارير السنوية.
                        'academic_year_id'    => $loan->academic_year_id ?? $data['academic_year_id'],
                        'amount'              => number_format($row['amount'], 2, '.', ''),
                        'repaid_at'           => $repaidAt,
                        'method'              => EmployeeAdvanceRepayment::METHOD_SALARY_DEDUCTION,
                        'salary_id'           => $salary->id,
                        'notes'               => 'قسط مخصوم ضمن الراتب رقم ' . $salary->id,
                        'created_by'          => $userId,
                    ]);

                    // لا سطر في الدفتر: الخصم من الراتب لا يُدخل مالاً إلى الدرج،
                    // وأثره النقدي مأخوذ سلفاً في صافي الراتب المُسقَط أدناه.
                    $loan->recalculateSettlement();
                }

                // راتب ابتلعته الخصومات بالكامل لم يخرج منه مليم اليوم، فلا سطر له في الدفتر.
                if ($net > 0) {
                    $this->ledger->recordSalary($salary);
                }

                return $salary;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $salary->load([
                'employee:id,first_name,last_name',
                'academicYear:id,name',
                'settledAdvances:id,settled_by_salary_id,amount,advance_date,reason',
            ]),
            201
        );
    }

    public function show(Salary $salary): JsonResponse
    {
        return response()->json($salary->load([
            'employee',
            'academicYear',
            'cancelledBy:id,first_name,last_name',
            'settledAdvances:id,settled_by_salary_id,amount,advance_date,reason',
        ]));
    }

    /**
     * التعديل لا يمسّ الخصومات.
     *
     * تغيير الخصم يعني إعادة فتح سلفة مخلّصة وربطها براتب آخر، وهو مسار
     * يسهل أن يُخطئ فيه. الطريق الموثّق: إلغاء الراتب ثم تسجيله من جديد.
     */
    public function update(Request $request, Salary $salary): JsonResponse
    {
        if ($salary->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل راتب ملغى'], 422);
        }

        if ($request->has('advance_ids') || $request->has('loan_deductions')) {
            return response()->json([
                'message' => 'لتعديل الخصومات ألغِ الراتب ثم سجّله من جديد',
            ], 422);
        }

        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
            'gross_amount' => ['sometimes', 'numeric', 'min:0.01'],
            'period_from' => ['sometimes', 'date'],
            'period_to' => ['sometimes', 'date'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('gross_amount', $data)) {
            $net = round((float) $data['gross_amount'] - (float) $salary->advance_deduction, 2);

            if ($net < 0) {
                return response()->json([
                    'message' => 'الراتب الخام أقلّ من الخصومات المحسوبة عليه',
                ], 422);
            }

            $data['amount'] = number_format($net, 2, '.', '');
        }

        // إعادة إسقاط الراتب في الدفتر بعد التعديل حتّى يبقى المبلغ والتاريخ متطابقَين.
        $salary = DB::transaction(function () use ($salary, $data) {
            $salary->update($data);
            $this->ledger->recordSalary($salary->fresh());

            return $salary->fresh();
        });

        return response()->json($salary->load(['employee:id,first_name,last_name', 'academicYear:id,name']));
    }

    /**
     * إلغاء موثّق للراتب بدل الحذف النهائي، مع سحب أثره من الدفتر النقدي
     * وإرجاع كل ما خُصم به إلى ذمّة الإطار.
     *
     * بدون هذا الإرجاع يبقى الإطار مديناً في الواقع ومبرّأً في النظام.
     */
    public function cancel(Request $request, Salary $salary): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($salary->cancelled_at) {
            return response()->json(['message' => 'هذا الراتب ملغى مسبقاً'], 422);
        }

        $userId = $request->user()?->id;

        DB::transaction(function () use ($salary, $data, $userId) {
            $salary->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $userId,
                'cancellation_reason' => $data['reason'],
            ]);

            // التسبقات: تُفكّ عن الراتب ويُعاد احتساب المخلَّص منها اشتقاقاً
            // من ردّياتها القائمة. التصفير الأعمى كان يمحو ردّاً نقديّاً سابقاً
            // فيُطالَب الإطار ثانية بمال دفعه فعلاً.
            $reopened = EmployeeAdvance::where('settled_by_salary_id', $salary->id)->get();

            foreach ($reopened as $advance) {
                $repaid = (float) $advance->repayments()->whereNull('cancelled_at')->sum('amount');
                $amount = (float) $advance->amount;

                $advance->update([
                    'settled_by_salary_id' => null,
                    'settled_amount'       => number_format(round($repaid, 2), 2, '.', ''),
                    'status'               => $repaid <= 0
                        ? EmployeeAdvance::STATUS_PENDING
                        : ($repaid >= $amount ? EmployeeAdvance::STATUS_SETTLED : EmployeeAdvance::STATUS_PARTIAL),
                ]);
            }

            // أقساط السلف التي خُصمت بهذا الراتب تسقط معه: الراتب لم يُدفع،
            // فالقسط لم يُخصم من شيء، والدَّين يعود كما كان.
            $deductedRepayments = EmployeeAdvanceRepayment::where('salary_id', $salary->id)
                ->whereNull('cancelled_at')
                ->get();

            foreach ($deductedRepayments as $repayment) {
                $repayment->update([
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $userId,
                    'cancellation_reason' => 'إلغاء الراتب رقم ' . $salary->id . ': ' . $data['reason'],
                ]);

                $repayment->advance?->recalculateSettlement();
            }

            $this->ledger->cancelFor($salary, $userId, $data['reason']);
        });

        return response()->json(
            $salary->fresh()->load([
                'employee:id,first_name,last_name',
                'academicYear:id,name',
                'cancelledBy:id,first_name,last_name',
            ])
        );
    }
}
