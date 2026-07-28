<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAdvance;
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
     * خلاص راتب مع خصم التسبقات القائمة في معاملة واحدة.
     *
     * القابض يدخل الراتب الخام (500) ويختار التسبقات المراد خصمها (100)،
     * فيدفع فعليّاً 400 ويُسقِط الدفتر 400 وحدها. المائة خرجت من الدرج يوم
     * منح التسبقة وسُجّلت هناك، فإسقاطها ثانية كان سيُخرج المال مرّتين.
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
        $userId     = $request->user()?->id;

        try {
            $salary = DB::transaction(function () use ($data, $gross, $advanceIds, $userId) {
                $advances  = collect();
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
                        $remaining = round((float) $advance->amount - (float) $advance->settled_amount, 2);

                        if ($remaining <= 0) {
                            throw new RuntimeException('التسبقة رقم ' . $advance->id . ' مخلّصة مسبقاً');
                        }

                        $deduction += $remaining;
                    }

                    $deduction = round($deduction, 2);
                }

                $net = round($gross - $deduction, 2);

                if ($net < 0) {
                    throw new RuntimeException(
                        'مجموع التسبقات (' . number_format($deduction, 2, '.', '') . ') يتجاوز الراتب الخام (' . number_format($gross, 2, '.', '') . ')'
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

                // راتب ابتلعته التسبقات بالكامل لم يخرج منه مليم اليوم، فلا سطر له في الدفتر.
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
     * التعديل لا يمسّ التسبقات المخصومة.
     *
     * تغيير الخصم يعني إعادة فتح سلفة مخلّصة وربطها براتب آخر، وهو مسار
     * يسهل أن يُخطئ فيه. الطريق الموثّق: إلغاء الراتب ثم تسجيله من جديد.
     */
    public function update(Request $request, Salary $salary): JsonResponse
    {
        if ($salary->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل راتب ملغى'], 422);
        }

        if ($request->has('advance_ids')) {
            return response()->json([
                'message' => 'لتعديل التسبقات المخصومة ألغِ الراتب ثم سجّله من جديد',
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
                    'message' => 'الراتب الخام أقلّ من التسبقات المخصومة منه',
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
     * وإعادة فتح التسبقات التي خُصمت به.
     *
     * بدون إعادة الفتح، يبقى الإطار مديناً في الواقع ومبرّأً في النظام.
     */
    public function cancel(Request $request, Salary $salary): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($salary->cancelled_at) {
            return response()->json(['message' => 'هذا الراتب ملغى مسبقاً'], 422);
        }

        DB::transaction(function () use ($salary, $data, $request) {
            $salary->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            // التسبقة تُخصم كاملة دائماً، فإعادة فتحها إرجاع إلى الصفر لا طرح جزئي.
            EmployeeAdvance::where('settled_by_salary_id', $salary->id)->update([
                'settled_amount'       => 0,
                'status'               => EmployeeAdvance::STATUS_PENDING,
                'settled_by_salary_id' => null,
                'updated_at'           => now(),
            ]);

            $this->ledger->cancelFor($salary, $request->user()?->id, $data['reason']);
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
