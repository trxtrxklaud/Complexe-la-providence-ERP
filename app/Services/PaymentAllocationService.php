<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentFee;

/**
 * محرّك توزيع الدفعات (Payment Allocation).
 *
 * الترتيب الافتراضي لتوزيع أي مبلغ مقبوض:
 *   1) أقدم المتخلّدات أولاً (من السنوات السابقة وما يقدّم تواريخ استحقاقه)
 *   2) ثم استحقاقات السنة الحالية
 *   3) الفترات المستقبلية تُغطّى فقط إذا سمحت سياسة المؤسسة (توزيع الشهور
 *      الجديدة يقع عبر شاشة الاستخلاص نفسها في حدود السنة الحالية)
 *   4) الفائض الذي لا يرتبط بمستحقّ يُسجّل رصيداً دائناً (قبض مقدّم) —
 *      لا يتحوّل إلى مدخول تلقائياً أبداً.
 *
 * المحاسب يرى النتيجة قبل التثبيت ويستطيع تعديلها؛ الخدمة هذه هي المرجع
 * للمعاينة، وCollectionService/PaymentService تقبل التوزيع الصريح وتتحقق منه.
 */
class PaymentAllocationService
{
    /**
     * اقتراح توزيع مبلغ على متخلّدات تلميذ حسب الترتيب الافتراضي.
     *
     * @return array<string,mixed>
     */
    public function suggest(Student $student, float $amount, ?int $activeYearId = null): array
    {
        $activeYearId ??= AcademicYear::where('is_active', true)->value('id');
        $amount = round($amount, 2);

        $fees = StudentFee::query()
            ->where('status', '!=', 'paid')
            ->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))
            ->with(['enrollment:id,academic_year_id', 'feeType:id,name_ar'])
            ->get()
            ->filter(fn (StudentFee $fee) => $fee->outstanding() > 0)
            // الأقدم أولاً: السنة الدراسية الأصغر، ثم تاريخ الاستحقاق، ثم المعرّف.
            ->sortBy([
                ['enrollment.academic_year_id', 'asc'],
                ['due_date', 'asc'],
                ['id', 'asc'],
            ]);

        $prior = [];
        $current = [];
        $remaining = $amount;

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding = $fee->outstanding();
            $take = round(min($outstanding, $remaining), 2);

            if ($take <= 0) {
                continue;
            }

            $isPrior = $activeYearId !== null
                && $fee->enrollment
                && (int) $fee->enrollment->academic_year_id !== (int) $activeYearId;

            $line = [
                'student_fee_id' => (int) $fee->id,
                'description' => $fee->description ?? $fee->feeType?->name_ar ?? 'بند مستحق',
                'source_year_id' => $fee->enrollment?->academic_year_id,
                'due_date' => $fee->due_date?->toDateString(),
                'outstanding' => $outstanding,
                'amount' => $take,
                'is_prior_year' => $isPrior,
            ];

            if ($isPrior) {
                $prior[] = $line;
            } else {
                $current[] = $line;
            }

            $remaining = round($remaining - $take, 2);
        }

        return [
            'student_id' => (int) $student->id,
            'active_year_id' => $activeYearId,
            'total' => $amount,
            'allocated' => round($amount - $remaining, 2),
            // قبض مقدم / رصيد دائن — لا يُعتبر مدخولاً تلقائياً.
            'credit' => $remaining,
            'order' => 'oldest-first',
            'prior_year' => $prior,
            'current_year' => $current,
        ];
    }

    /**
     * التحقق من أن قائمة التوزيعات الصريحة صحيحة ومتناسقة مع مبلغ الدفعة.
     *
     * المبالغ موجبة، لا يتجاوز أي توزيع متبقّي رصيد الرسم، ولا يتجاوز المجموع
     * مبلغ الدفعة. يُعاد الملفّ مع كل التوزيعات (إعادة الترتيب للأقدم أولاً).
     *
     * @param  array<int,array{student_fee_id:int,amount:float}>  $allocations
     * @return array<int,array<string,mixed>>
     *
     * @throws \InvalidArgumentException
     */
    public function validate(Student $student, array $allocations, float $paymentAmount): array
    {
        $paymentAmount = round($paymentAmount, 2);
        $total = 0.0;

        // تحصيل صريح: نقرأ الرسوم المطلوبة بحماية القفل ونقيس متبقّيها.
        $result = [];

        foreach ($allocations as $allocation) {
            $feeId = (int) ($allocation['student_fee_id'] ?? 0);
            $amount = round((float) ($allocation['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            /** @var StudentFee|null $fee */
            $fee = StudentFee::query()
                ->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))
                ->with('enrollment:id,academic_year_id')
                ->find($feeId);

            if (! $fee) {
                throw new \InvalidArgumentException('رسم التلميذ رقم '.$feeId.' غير موجود لهذا التلميذ');
            }

            $outstanding = $fee->outstanding();

            if ($amount > $outstanding) {
                throw new \InvalidArgumentException(
                    'مبلغ التوزيع ('.number_format($amount, 2, '.', '')
                    .') يتجاوز المتبقّي ('.number_format($outstanding, 2, '.', '')
                    .') للرسم: '.$fee->description
                );
            }

            $total = round($total + $amount, 2);

            $result[] = [
                'student_fee_id' => (int) $fee->id,
                'description' => $fee->description,
                'amount' => $amount,
            ];
        }

        if ($total > $paymentAmount) {
            throw new \InvalidArgumentException(
                'مجموع التوزيعات ('.number_format($total, 2, '.', '')
                .') يتجاوز مبلغ الدفعة ('.number_format($paymentAmount, 2, '.', '').')'
            );
        }

        return $result;
    }
}
