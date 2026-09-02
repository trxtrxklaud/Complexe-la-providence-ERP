<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\StudentFee;

/**
 * معلوم الترسيم المقبوض لحظة تسجيل تلميذ أو تجديد ترسيمه.
 *
 * التصنيف ليس تفصيلاً تجميلياً: LedgerService لا يعرف أنّ الدفعة معلوم ترسيم، بل
 * يستنتج البند من الرسم المُخصّص لها. دفعة بلا تخصيص تسقط في «مداخيل أخرى»،
 * فيدخل المال الخزينة ويغيب عن معاليم التسجيل وعن كل تقرير يفصّل حسب البند.
 *
 * لذلك نضمن وجود رسم يُخصّص له المبلغ، بترتيب أولوية واضح:
 *   1) رسوم خطة سنوية (yearly) إن وُلّدت للمستوى — وهي الحالة المثلى
 *   2) وإلا فرسم مربوط بنوع رسم مُصنّف registration_fee يُنشَأ عند الحاجة
 *
 * ولماذا نُنشئ رسماً؟ لأنّ المدرسة قبضت مالاً مقابل الترسيم، فوجب أن يظهر في
 * ملفّ التلميذ ما قُبض ومقابل ماذا. دفعة بلا رسم هي مال بلا سبب في دفتر محاسبي.
 *
 * قاعدة التصنيف الحاسمة: البند يتبع تصريح القابض، لا قائمة الأسعار.
 * كان سعر نوع الرسم يسقّف التخصيص، فدفعة 70 د على نوع سعره 20 د تُنتج
 * 20 د في معاليم التسجيل و50 د في «مداخيل أخرى» — وهذا تشويه صامت للتقرير:
 * المال في الخزينة صحيح والبند خطأ، فيقرأ صاحب المدرسة معاليم تسجيل أقلّ من
 * الحقيقة ومداخيل أخرى منفوخة دون أن يظهر خطأ في أي مجموع. الأسعار في
 * fee_types مرجع افتراضي لا سلطة على مبلغ قُبض فعلاً وصرّح القابض ببنده.
 */
class RegistrationPaymentService
{
    public function __construct(private PaymentService $payments) {}

    /**
     * @param  array<string,mixed>  $data  الحقول المتحقّق منها القادمة من شاشة التسجيل.
     */
    public function record(Enrollment $enrollment, array $data, ?int $createdBy = null): ?Payment
    {
        $amount = round((float) ($data['registration_amount'] ?? 0), 2);

        if ($amount <= 0) {
            return null;
        }

        $feeItems = $data['fee_items'] ?? null;

        // مفتاح عدم التكرار: client_request_id إلزامي لكل طلب دفع لمنع التكرار دون توليد مفاتيح تخمينية
        $idempotencyKey = $data['client_request_id']
            ?? $data['idempotency_key']
            ?? ($data['request_id'] ?? null);

        if (empty($idempotencyKey) || ! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'client_request_id' => ['معرّف الطلب client_request_id إلزامي لإتمام عملية الدفع ومنع التكرار.'],
            ]);
        }

        return $this->payments->recordPayment([
            'student_id'      => $enrollment->student_id,
            'enrollment_id'   => $enrollment->id,
            'amount'          => $amount,
            'payment_date'    => $data['payment_date'] ?? now()->toDateString(),
            'method'          => $data['payment_method'] ?? 'cash',
            'notes'           => $data['payment_notes'] ?? 'معلوم الترسيم عند التسجيل',
            'idempotency_key' => trim($idempotencyKey),
            'allocations'     => $this->allocations($enrollment, $amount, $feeItems),
        ], $createdBy);
    }

    /**
     * توزيع المبلغ على رسوم الترسيم واللوازم (أو الرسوم السنوية الافتراضية).
     *
     * @param  array<int,array{fee_type_id?:int,amount:float,description?:string}>|null  $feeItems
     * @return array<int,array{student_fee_id:int,amount:float}>
     */
    private function allocations(Enrollment $enrollment, float $amount, ?array $feeItems = null): array
    {
        if (!empty($feeItems) && is_array($feeItems)) {
            $allocations = [];
            $sumAllocated = 0.0;

            foreach ($feeItems as $item) {
                $itemAmount = round((float) ($item['amount'] ?? 0), 2);
                if ($itemAmount <= 0) {
                    continue;
                }

                $rawId = !empty($item['fee_type_id']) ? (int) $item['fee_type_id'] : null;
                $feeType = $rawId ? FeeType::find($rawId) : null;
                $desc = trim($item['description'] ?? '');

                if (!$feeType && !empty($desc)) {
                    $feeType = FeeType::where('name_ar', $desc)
                        ->orWhere('name_ar', 'LIKE', "%{$desc}%")
                        ->first();
                }

                if (!$feeType && !empty($desc)) {
                    $cat = CashTransaction::CATEGORY_PRODUCT_SALE;
                    if (str_contains($desc, 'ترسيم') || str_contains($desc, 'تسجيل')) {
                        $cat = CashTransaction::CATEGORY_REGISTRATION_FEE;
                    } elseif (str_contains($desc, 'منظومة') || str_contains($desc, 'ERP') || str_contains($desc, 'vie')) {
                        $cat = CashTransaction::CATEGORY_OTHER_INCOME;
                    }

                    $feeType = FeeType::create([
                        'name_ar' => $desc,
                        'price' => $itemAmount,
                        'ledger_category' => $cat,
                        'is_active' => true,
                    ]);
                }

                $feeTypeId = $feeType?->id ?: $rawId;
                $feeDesc = $desc ?: ($feeType?->name_ar ?: 'معلوم ترسيم/لوازم');

                $fee = StudentFee::firstOrCreate(
                    [
                        'enrollment_id' => $enrollment->id,
                        'fee_type_id'   => $feeTypeId,
                    ],
                    [
                        'description' => $feeDesc,
                        'amount_due'  => $itemAmount,
                        'due_date'    => $enrollment->enrollment_date ?? now()->toDateString(),
                        'status'      => 'pending',
                    ]
                );

                if (round((float) $fee->amount_due, 2) < $itemAmount) {
                    $fee->update(['amount_due' => $itemAmount]);
                }

                $allocations[] = [
                    'student_fee_id' => (int) $fee->id,
                    'amount'         => $itemAmount,
                ];
                $sumAllocated += $itemAmount;
            }

            $remaining = round($amount - $sumAllocated, 2);
            if ($remaining > 0) {
                $extra = $this->absorbingFee($enrollment);
                if ($extra) {
                    $alreadyOnExtra = round((float) $extra->amount_due, 2);
                    $extra->update(['amount_due' => round($alreadyOnExtra + $remaining, 2)]);
                    $allocations[] = [
                        'student_fee_id' => (int) $extra->id,
                        'amount'         => $remaining,
                    ];
                }
            }

            if (!empty($allocations)) {
                return $allocations;
            }
        }

        $fees = $this->registrationFees($enrollment, $amount);

        /** @var array<int,float> $planned */
        $planned   = [];
        $remaining = $amount;

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $due = round((float) $fee->amount_due - $this->allocatedOn($fee), 2);

            if ($due <= 0) {
                continue;
            }

            $take = min($due, $remaining);

            $planned[$fee->id] = round(($planned[$fee->id] ?? 0.0) + $take, 2);
            $remaining         = round($remaining - $take, 2);
        }

        if ($remaining > 0) {
            $extra = $this->absorbingFee($enrollment);

            if ($extra) {
                $inFlight = $planned[$extra->id] ?? 0.0;
                $needed   = round($this->allocatedOn($extra) + $inFlight + $remaining, 2);

                // رفع المستحقّ إلى ما قُبض فعلاً: الرسم يوثّق ما طالبت به المدرسة،
                // وقد طالبت بالمبلغ المقبوض بدليل قبضه.
                if (round((float) $extra->amount_due, 2) < $needed) {
                    $extra->update(['amount_due' => $needed]);
                }

                $planned[$extra->id] = round($inFlight + $remaining, 2);
                $remaining           = 0.0;
            }
        }

        $allocations = [];

        foreach ($planned as $feeId => $allocated) {
            if ($allocated <= 0) {
                continue;
            }

            $allocations[] = [
                'student_fee_id' => (int) $feeId,
                'amount'         => $allocated,
            ];
        }

        return $allocations;
    }

    /**
     * ما خُصّص فعلاً لرسم من دفعات غير ملغاة.
     */
    private function allocatedOn(StudentFee $fee): float
    {
        return round((float) $fee->paymentAllocations()
            ->whereHas('payment', fn ($query) => $query->whereNull('cancelled_at'))
            ->sum('amount_allocated'), 2);
    }

    /**
     * الرسم الذي يستوعب الفائض: رسم نوع الترسيم لهذا الترسيم، يُنشَأ عند الحاجة.
     *
     * لا نرفع مستحقّ رسوم الخطة السنوية أبداً، فهي قائمة أسعار المؤسسة ويجب أن
     * تبقى كما قرّرتها الإدارة. الفائض يذهب إلى رسم مستقلّ من نوع مُصنّف
     * registration_fee، فيبقى البند في الدفتر معاليم تسجيل والخطة سليمة.
     */
    private function absorbingFee(Enrollment $enrollment): ?StudentFee
    {
        $feeType = $this->registrationFeeType();

        if (! $feeType) {
            return null;
        }

        return StudentFee::firstOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'fee_type_id'   => $feeType->id,
            ],
            [
                'description' => $feeType->name_ar ?: 'معلوم الترسيم',
                'amount_due'  => 0,
                'due_date'    => $enrollment->enrollment_date ?? now()->toDateString(),
                'status'      => 'pending',
            ]
        );
    }

    /**
     * رسوم الترسيم المتاحة لهذا الترسيم، مع إنشاء رسم عند الحاجة.
     *
     * @return \Illuminate\Support\Collection<int,StudentFee>
     */
    private function registrationFees(Enrollment $enrollment, float $amount)
    {
        $planned = StudentFee::where('enrollment_id', $enrollment->id)
            ->whereHas('feePlan', fn ($query) => $query->where('frequency', 'yearly'))
            ->orderBy('due_date')
            ->get();

        if ($planned->isNotEmpty()) {
            return $planned;
        }

        $feeType = $this->registrationFeeType();

        if (! $feeType) {
            // لا نوع رسم مصنّف للترسيم: نترك المبلغ يدخل الخزينة كمدخول آخر
            // بدل رفض الترسيم. إخفاء مال مقبوض أخطر من تصنيفه تصنيفاً عامّاً.
            return collect();
        }

        $price = (float) $feeType->price;

        $fee = StudentFee::firstOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'fee_type_id'   => $feeType->id,
            ],
            [
                'description' => $feeType->name_ar ?: 'معلوم الترسيم',
                'amount_due'  => $price > 0 ? $price : $amount,
                'due_date'    => $enrollment->enrollment_date ?? now()->toDateString(),
                'status'      => 'pending',
            ]
        );

        return collect([$fee]);
    }

    /**
     * نوع الرسم المعتمد للترسيم: المُصرّح بـ ledger_category أوّلاً،
     * ثمّ أي نوع يستنتج من اسمه أنّه ترسيم (للأنواع القديمة قبل إضافة العمود).
     */
    private function registrationFeeType(): ?FeeType
    {
        $declared = FeeType::where('is_active', true)
            ->where('ledger_category', CashTransaction::CATEGORY_REGISTRATION_FEE)
            ->orderBy('id')
            ->first();

        if ($declared) {
            return $declared;
        }

        return FeeType::where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (FeeType $type) => $type->resolveLedgerCategory() === CashTransaction::CATEGORY_REGISTRATION_FEE);
    }
}
