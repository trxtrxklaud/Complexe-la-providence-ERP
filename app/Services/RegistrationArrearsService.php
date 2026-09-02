<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\StudentFee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegistrationArrearsService
{
    /**
     * احتساب وتوليد متخلدات الترسيم فقط بعد انقضاء المهلة المحددة (نهاية شهر سبتمبر).
     *
     * القاعدة:
     * - خلال شهر الترسيم (سبتمبر): التلميذ ليس مديناً، بل في انتظار استكمال الترسيم.
     * - بعد انقضاء المهلة: (تلاميذ الأقسام) - (من أتموا الترسيم المالي) = المتخلدون.
     * - لا تُنشأ أي حركة خزينة ولا سند قبض.
     * - يُمنع تكرار إنشاء الرسم إذا كان موجوداً مسبقاً.
     * - لا تُمَس الديون القديمة في manual_student_debts.
     *
     * @param  string|null  $asOfDate  تاريخ الفحص (افتراضياً اليوم)
     * @return array{status: string, message: string, total_enrolled: int, paid_count: int, arrears_created: int, already_existing_arrears: int}
     */
    public function generateArrearsAfterDeadline(?string $asOfDate = null): array
    {
        $checkDate = $asOfDate ? Carbon::parse($asOfDate) : now();

        $activeYear = AcademicYear::where('is_active', true)->first();
        if (! $activeYear) {
            return [
                'status' => 'error',
                'message' => 'لا توجد سنة دراسية نشطة.',
                'total_enrolled' => 0,
                'paid_count' => 0,
                'arrears_created' => 0,
                'already_existing_arrears' => 0,
            ];
        }

        // موعد انتهاء مهلة الترسيم لشهر سبتمبر (30 سبتمبر من سنة بداية السنة النشطة)
        $yearStart = $activeYear->start_date ? Carbon::parse($activeYear->start_date) : Carbon::create($checkDate->year, 9, 1);
        $deadline = Carbon::create($yearStart->year, 9, 30)->endOfDay();

        // حماية موعد المهلة: لا يعمل التوليد قبل انتهاء المهلة
        if ($checkDate->lt($deadline)) {
            return [
                'status' => 'pending_deadline',
                'message' => 'مهلة الترسيم لشهر سبتمبر ما زالت سارية حتى تاريخ ' . $deadline->toDateString() . '. لا يتم إنشاء متخلدات قبل انتهاء الشهر.',
                'total_enrolled' => Enrollment::where('academic_year_id', $activeYear->id)->where('status', 'active')->count(),
                'paid_count' => 0,
                'arrears_created' => 0,
                'already_existing_arrears' => 0,
            ];
        }

        return DB::transaction(function () use ($activeYear, $deadline) {
            $enrollments = Enrollment::where('academic_year_id', $activeYear->id)
                ->where('status', 'active')
                ->with(['studentFees.feeType'])
                ->lockForUpdate()
                ->get();

            $totalEnrolled = $enrollments->count();
            $paidCount = 0;
            $arrearsCreated = 0;
            $alreadyExistingArrears = 0;

            // نوع رسم الترسيم المعتمد
            $regFeeType = FeeType::where('is_active', true)
                ->where('ledger_category', CashTransaction::CATEGORY_REGISTRATION_FEE)
                ->first()
                ?? FeeType::where('name_ar', 'like', '%ترسيم%')->first();

            $feeTypeId = $regFeeType?->id;
            $defaultRegPrice = $regFeeType && (float) $regFeeType->price > 0 ? (float) $regFeeType->price : 0.0;

            foreach ($enrollments as $enrollment) {
                // استخراج معلوم الترسيم: الأولوية لسعر نوع الرسم، أو خطة رسوم الترسيم المحددة للمستوى
                $expectedAmount = $defaultRegPrice;

                $regPlan = FeePlan::where('level_id', $enrollment->level_id)
                    ->where('academic_year_id', $activeYear->id)
                    ->where(function ($q) {
                        $q->where('name', 'like', '%ترسيم%')
                            ->orWhere('name', 'like', '%تسجيل%')
                            ->orWhere('frequency', 'one_time');
                    })
                    ->first();

                if ($regPlan && (float) $regPlan->amount > 0) {
                    $expectedAmount = (float) $regPlan->amount;
                }

                // منع إنشاء أي رسم بمبلغ صفري أو سالب
                if ($expectedAmount <= 0) {
                    continue;
                }

                // التحقق هل أتم التلميذ الترسيم المالي ودفع معاليمه
                $paidRegFee = $enrollment->studentFees->first(function (StudentFee $fee) {
                    $isReg = ($fee->feeType?->ledger_category === CashTransaction::CATEGORY_REGISTRATION_FEE)
                        || str_contains($fee->description, 'ترسيم')
                        || str_contains($fee->description, 'تسجيل');

                    return $isReg && $fee->status === 'paid';
                });

                if ($paidRegFee) {
                    $paidCount++;
                    continue;
                }

                // التلميذ لم يدفع معلوم الترسيم بعد انتهاء المهلة → التحقق من عدم التكرار
                $existingUnpaidFee = $enrollment->studentFees->first(function (StudentFee $fee) use ($feeTypeId) {
                    if ($feeTypeId && (int) $fee->fee_type_id === (int) $feeTypeId) {
                        return true;
                    }

                    return str_contains($fee->description, 'ترسيم') || str_contains($fee->description, 'تسجيل');
                });

                if ($existingUnpaidFee) {
                    // موجود مسبقاً: نمنع التكرار ونضمن عدم تكرار السجل
                    $alreadyExistingArrears++;
                } else {
                    // إنشاء رسم الترسيم كمتخلد مستحق بدون دفع وبدون حركة خزينة
                    StudentFee::create([
                        'enrollment_id' => $enrollment->id,
                        'fee_plan_id' => $regPlan?->id,
                        'fee_type_id' => $feeTypeId,
                        'description' => $regFeeType?->name_ar ?: 'معلوم الترسيم (متخلّد)',
                        'amount_due' => $expectedAmount,
                        'due_date' => $deadline->toDateString(),
                        'status' => 'pending',
                    ]);
                    $arrearsCreated++;
                }
            }

            return [
                'status' => 'success',
                'message' => "تم جرد المتخلدات بنجاح: تم إنشاء {$arrearsCreated} متخلد جديد، و{$alreadyExistingArrears} متخلد مسجل مسبقاً.",
                'total_enrolled' => $totalEnrolled,
                'paid_count' => $paidCount,
                'arrears_created' => $arrearsCreated,
                'already_existing_arrears' => $alreadyExistingArrears,
            ];
        });
    }
}
