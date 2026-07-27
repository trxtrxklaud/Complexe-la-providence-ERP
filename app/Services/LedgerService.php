<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Salary;
use App\Models\StudentFee;
use App\Models\TreasuryWithdrawal;
use Illuminate\Database\Eloquent\Model;

/**
 * طبقة الدفتر النقدي المركزي.
 *
 * كل مستند مالي في المنصة (دفعة، راتب، مصروف، سلفة، سحب) يُسقِط أثره النقدي هنا.
 * التقارير لا تقرأ من الجداول المصدرية أبداً، بل من cash_transactions حصراً،
 * فتتطابق الأرقام في كل الشاشات دون احتساب مزدوج.
 *
 * الإسقاط idempotent: إعادة النداء لنفس المستند تُحدّث السطر ولا تضيف نسخة ثانية،
 * اعتماداً على قيد الفرادة (source_type, source_id, category).
 */
class LedgerService
{
    /**
     * إسقاط سطر واحد في الدفتر بطريقة لا تتكرّر.
     */
    public function post(
        Model $source,
        string $category,
        string $direction,
        float $amount,
        string $date,
        ?int $academicYearId = null,
        ?string $description = null,
        ?int $createdBy = null
    ): CashTransaction {
        return CashTransaction::updateOrCreate(
            [
                'source_type' => $source->getMorphClass(),
                'source_id'   => $source->getKey(),
                'category'    => $category,
            ],
            [
                'transaction_date'    => $date,
                'direction'           => $direction,
                'amount'              => round($amount, 2),
                'academic_year_id'    => $academicYearId,
                'description'         => $description,
                'created_by'          => $createdBy,
                // إعادة الإسقاط تُعيد تفعيل السطر إن كان ملغى سابقاً.
                'cancelled_at'        => null,
                'cancelled_by'        => null,
                'cancellation_reason' => null,
            ]
        );
    }

    /**
     * إلغاء كل أسطر الدفتر المتولّدة عن مستند معيّن.
     * لا يُحذف أي سطر أبداً حفاظاً على مسار التدقيق.
     */
    public function cancelFor(Model $source, ?int $userId = null, ?string $reason = null): int
    {
        return CashTransaction::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $userId,
                'cancellation_reason' => $reason,
                'updated_at'          => now(),
            ]);
    }

    /**
     * إلغاء الأسطر التي لم تعُد معنيّة بعد إعادة احتساب مستند (مثلاً تغيّر توزيع دفعة).
     *
     * @param  array<int,string>  $keptCategories
     */
    private function pruneStale(Model $source, array $keptCategories, ?int $userId = null): void
    {
        CashTransaction::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereNull('cancelled_at')
            ->when($keptCategories !== [], fn ($q) => $q->whereNotIn('category', $keptCategories))
            ->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $userId,
                'cancellation_reason' => 'إعادة احتساب بعد تعديل المستند',
                'updated_at'          => now(),
            ]);
    }

    /**
     * دفعة تلميذ → مداخيل، مفصّلة حسب نوع الرسم المُخصّص له.
     *
     * التصنيف يعتمد على frequency في fee_plans وليس على مطابقة نصوص، ضماناً للدقة:
     * - yearly  → معاليم التسجيل
     * - monthly → معاليم الأشهر
     * وما لم يُوزّع من الدفعة يُسجّل كمداخيل أخرى، فلا يضيع أي مليم من الصندوق.
     */
    public function recordPayment(Payment $payment): void
    {
        if ($payment->cancelled_at !== null) {
            $this->cancelFor($payment, $payment->cancelled_by, $payment->cancellation_reason);

            return;
        }

        $payment->loadMissing(['paymentAllocations.studentFee.feePlan', 'enrollment']);

        $buckets   = [];
        $allocated = 0.0;

        foreach ($payment->paymentAllocations as $allocation) {
            $amount = (float) $allocation->amount_allocated;

            if ($amount <= 0) {
                continue;
            }

            $allocated += $amount;
            $category = $this->categoryForFee($allocation->studentFee);
            $buckets[$category] = ($buckets[$category] ?? 0.0) + $amount;
        }

        $remainder = round((float) $payment->amount - $allocated, 2);

        if ($remainder > 0) {
            $key = CashTransaction::CATEGORY_OTHER_INCOME;
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $remainder;
        }

        $academicYearId = $payment->enrollment?->academic_year_id;
        $date           = $payment->payment_date?->toDateString()
            ?? (string) $payment->payment_date;

        foreach ($buckets as $category => $amount) {
            $this->post(
                source: $payment,
                category: $category,
                direction: CashTransaction::DIRECTION_IN,
                amount: $amount,
                date: $date,
                academicYearId: $academicYearId,
                description: 'دفعة رقم ' . $payment->id,
                createdBy: $payment->created_by,
            );
        }

        $this->pruneStale($payment, array_keys($buckets), $payment->created_by);
    }

    /**
     * راتب إطار → مصاريف (بند الأجور).
     */
    public function recordSalary(Salary $salary): void
    {
        if ($salary->cancelled_at !== null) {
            $this->cancelFor($salary, $salary->cancelled_by, $salary->cancellation_reason);

            return;
        }

        $salary->loadMissing('employee');

        $date = $salary->paid_at?->toDateString()
            ?? $salary->period_to?->toDateString()
            ?? now()->toDateString();

        $this->post(
            source: $salary,
            category: CashTransaction::CATEGORY_SALARY,
            direction: CashTransaction::DIRECTION_OUT,
            amount: (float) $salary->amount,
            date: $date,
            academicYearId: $salary->academic_year_id,
            description: 'راتب: ' . ($salary->employee?->full_name ?? ('إطار #' . $salary->employee_id)),
            createdBy: $salary->created_by,
        );
    }

    /**
     * مصروف عام → مصاريف.
     */
    public function recordExpense(Expense $expense): void
    {
        if ($expense->cancelled_at !== null) {
            $this->cancelFor($expense, $expense->cancelled_by, $expense->cancellation_reason);

            return;
        }

        $this->post(
            source: $expense,
            category: CashTransaction::CATEGORY_EXPENSE,
            direction: CashTransaction::DIRECTION_OUT,
            amount: (float) $expense->amount,
            date: $expense->expense_date?->toDateString() ?? now()->toDateString(),
            academicYearId: $expense->academic_year_id,
            description: $expense->label,
            createdBy: $expense->created_by,
        );
    }

    /**
     * سلفة إطار → خروج نقدي في بند مستقل عن الأجور.
     */
    public function recordEmployeeAdvance(EmployeeAdvance $advance): void
    {
        if ($advance->cancelled_at !== null) {
            $this->cancelFor($advance, $advance->cancelled_by, $advance->cancellation_reason);

            return;
        }

        $advance->loadMissing('employee');

        $this->post(
            source: $advance,
            category: CashTransaction::CATEGORY_EMPLOYEE_ADVANCE,
            direction: CashTransaction::DIRECTION_OUT,
            amount: (float) $advance->amount,
            date: $advance->advance_date?->toDateString() ?? now()->toDateString(),
            academicYearId: $advance->academic_year_id,
            description: 'سلفة: ' . ($advance->employee?->full_name ?? ('إطار #' . $advance->employee_id)),
            createdBy: $advance->created_by,
        );
    }

    /**
     * سحب من الخزينة → حركة مستقلة لا تدخل في الدخل الصافي.
     */
    public function recordWithdrawal(TreasuryWithdrawal $withdrawal): void
    {
        if ($withdrawal->cancelled_at !== null) {
            $this->cancelFor($withdrawal, $withdrawal->cancelled_by, $withdrawal->cancellation_reason);

            return;
        }

        $this->post(
            source: $withdrawal,
            category: CashTransaction::CATEGORY_WITHDRAWAL,
            direction: CashTransaction::DIRECTION_OUT,
            amount: (float) $withdrawal->amount,
            date: $withdrawal->withdrawn_at?->toDateString() ?? now()->toDateString(),
            academicYearId: $withdrawal->academic_year_id,
            description: $withdrawal->type ?: 'سحب من الخزينة',
            createdBy: $withdrawal->created_by,
        );
    }

    /**
     * تصنيف رسم التلميذ إلى بند مداخيل.
     */
    private function categoryForFee(?StudentFee $fee): string
    {
        return match ($fee?->feePlan?->frequency) {
            'yearly' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            default  => CashTransaction::CATEGORY_MONTHLY_FEE,
        };
    }
}
