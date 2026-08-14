<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\ClubMonthlyFee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Salary;
use App\Models\StudentFee;
use App\Models\TreasuryWithdrawal;
use Illuminate\Database\Eloquent\Model;

/**
 * طبقة الدفتر النقدي المركزي.
 *
 * كل مستند مالي في المنصة (دفعة، راتب، مصروف، سلفة، ردّ سلفة، سحب) يُسقِط أثره النقدي هنا.
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
                'source_id' => $source->getKey(),
                'category' => $category,
            ],
            [
                'transaction_date' => $date,
                'direction' => $direction,
                'amount' => self::decimal($amount),
                'academic_year_id' => $academicYearId,
                'description' => $description,
                'created_by' => $createdBy,
                // إعادة الإسقاط تُعيد تفعيل السطر إن كان ملغى سابقاً.
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ]
        );
    }

    /**
     * تحويل المبلغ إلى نص عشري برقمين قبل تخزينه.
     *
     * الفاصلة العائمة لا تمثّل المليمات تمثيلاً دقيقاً، ومكتبة الحساب العشري
     * تُحذّر من تمرير float وستمنعه لاحقاً. التنسيق إلى نص هنا يجعل القيمة
     * تصل إلى العمود العشري دون وسيط عائم.
     */
    private static function decimal(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
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
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
                'updated_at' => now(),
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
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => 'إعادة احتساب بعد تعديل المستند',
                'updated_at' => now(),
            ]);
    }

    /**
     * دفعة تلميذ → مداخيل، مفصّلة حسب بند كل رسم مُخصّص لها.
     *
     * وما لم يُوزّع من الدفعة يُسجّل كمداخيل أخرى، فلا يضيع أي مليم من الصندوق.
     */
    public function recordPayment(Payment $payment): void
    {
        if ($payment->cancelled_at !== null) {
            $this->cancelFor($payment, $payment->cancelled_by, $payment->cancellation_reason);

            return;
        }

        $payment->loadMissing([
            'paymentAllocations.studentFee.enrollment',
            'paymentAllocations.studentFee.feePlan',
            'paymentAllocations.studentFee.feeType',
            'enrollment',
        ]);

        // سنة الدفعة: تسجيلها إن وُجد، وإلا فالسنة النشطة — عليها يتبنّى
        // تمييز «دَين سنة سابقة» عن «مدخول السنة الحالية».
        $paymentYearId = $payment->enrollment?->academic_year_id
            ?? AcademicYear::where('is_active', true)->value('id');

        $buckets = [];
        $allocated = 0.0;

        foreach ($payment->paymentAllocations as $allocation) {
            $amount = (float) $allocation->amount_allocated;

            if ($amount <= 0) {
                continue;
            }

            $allocated += $amount;
            $fee = $allocation->studentFee;

            // قبض دين من سنة سابقة ليس مدخولاً للسنة الحالية: يُسجَّل في الخزينة
            // كبند مستقل (prior_year_debt) لا يدخل في الدخل الصافي ولا في المداخيل.
            $feeYearId = $fee?->enrollment?->academic_year_id;
            $isPriorYearDebt = $feeYearId !== null
                && $paymentYearId !== null
                && (int) $feeYearId !== (int) $paymentYearId;

            $category = $isPriorYearDebt
                ? CashTransaction::CATEGORY_PRIOR_YEAR_DEBT
                : $this->categoryForFee($fee);

            $buckets[$category] = ($buckets[$category] ?? 0.0) + $amount;
        }

        $remainder = round((float) $payment->amount - $allocated, 2);

        if ($remainder > 0) {
            $key = CashTransaction::CATEGORY_OTHER_INCOME;
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $remainder;
        }

        $academicYearId = $payment->enrollment?->academic_year_id;
        $date = $payment->payment_date?->toDateString()
            ?? (string) $payment->payment_date;

        foreach ($buckets as $category => $amount) {
            $this->post(
                source: $payment,
                category: $category,
                direction: CashTransaction::DIRECTION_IN,
                amount: $amount,
                date: $date,
                academicYearId: $academicYearId,
                description: 'دفعة رقم '.$payment->id,
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
            description: 'راتب: '.($salary->employee?->full_name ?? ('إطار #'.$salary->employee_id)),
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
     * خلاص معلوم نادي تلميذ → مداخيل في بند معاليم النوادي.
     */
    public function recordClubFeePayment(ClubMonthlyFee $monthlyFee): void
    {
        if ($monthlyFee->cancelled_at !== null || (float) $monthlyFee->amount_paid <= 0) {
            $this->cancelFor($monthlyFee, $monthlyFee->cancelled_by, $monthlyFee->cancellation_reason);

            return;
        }

        $monthlyFee->loadMissing(['student', 'club']);

        $studentName = trim(($monthlyFee->student?->first_name ?? '').' '.($monthlyFee->student?->last_name ?? ''));
        $description = 'معلوم نادي '.($monthlyFee->club?->name ?? '').': '.$studentName.' ('.$monthlyFee->month.')';

        $this->post(
            source: $monthlyFee,
            category: CashTransaction::CATEGORY_CLUB_FEE,
            direction: CashTransaction::DIRECTION_IN,
            amount: (float) $monthlyFee->amount_paid,
            date: $monthlyFee->paid_at?->toDateString() ?? now()->toDateString(),
            academicYearId: $monthlyFee->academic_year_id,
            description: $description,
            createdBy: $monthlyFee->created_by,
        );
    }

    /**
     * تسبقة أو سلفة إطار → خروج نقدي في بند مستقل عن الأجور.
     *
     * البند في الدفتر واحد للنوعين لأنّ الأثر النقدي واحد، لكن البيان يفرق بينهما:
     * التسبقة تُخصم من راتب الشهر نفسه، والسلفة دَين يُردّ لاحقاً. من يقرأ سجلّ
     * الخزينة يجب أن يعرف أيّهما أمامه دون فتح ملفّ الإطار.
     */
    public function recordEmployeeAdvance(EmployeeAdvance $advance): void
    {
        if ($advance->cancelled_at !== null) {
            $this->cancelFor($advance, $advance->cancelled_by, $advance->cancellation_reason);

            return;
        }

        $advance->loadMissing('employee');

        $typeLabel = EmployeeAdvance::TYPE_LABELS[$advance->type] ?? 'سلفة';

        $this->post(
            source: $advance,
            category: CashTransaction::CATEGORY_EMPLOYEE_ADVANCE,
            direction: CashTransaction::DIRECTION_OUT,
            amount: (float) $advance->amount,
            date: $advance->advance_date?->toDateString() ?? now()->toDateString(),
            academicYearId: $advance->academic_year_id,
            description: $typeLabel.': '.($advance->employee?->full_name ?? ('إطار #'.$advance->employee_id)),
            createdBy: $advance->created_by,
        );
    }

    /**
     * ردّ سلفة → دخل في بند خلاص السلفة، ولكن للردّ النقدي وحده.
     *
     * الخصم من الراتب لا يمرّ بالصندوق إطلاقاً: الإطار لم يُخرج مالاً من جيبه،
     * بل قبض راتباً أقل، والراتب أُسقِط صافياً أصلاً. إسقاط سطر دخل له ينفخ
     * المداخيل بمبلغ لم يدخل الدرج، ويبقي الرصيد صحيحاً بالصدفة في المجموع
     * بينما المداخيل والمصاريف كلتاهما منفوختان — وهذا تزوير للتقرير لا خطأ حساب.
     */
    public function recordAdvanceRepayment(EmployeeAdvanceRepayment $repayment): void
    {
        if ($repayment->cancelled_at !== null || ! $repayment->affectsCash()) {
            $this->cancelFor($repayment, $repayment->cancelled_by, $repayment->cancellation_reason);

            return;
        }

        $repayment->loadMissing('employee');

        $this->post(
            source: $repayment,
            category: CashTransaction::CATEGORY_ADVANCE_REPAYMENT,
            direction: CashTransaction::DIRECTION_IN,
            amount: (float) $repayment->amount,
            date: $repayment->repaid_at?->toDateString() ?? now()->toDateString(),
            academicYearId: $repayment->academic_year_id,
            description: 'خلاص سلفة: '.($repayment->employee?->full_name ?? ('إطار #'.$repayment->employee_id)),
            createdBy: $repayment->created_by,
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
     * تصنيف رسم التلميذ إلى بند مداخيل، بأولوية بنيوية لا نصّية:
     *   1) خطة الرسوم (fee_plans.frequency) للرسوم المُولّدة تلقائياً
     *   2) نوع الرسم (fee_types.ledger_category) للرسوم المُستخلَصة يدوياً
     *   3) معاليم الأشهر كقيمة افتراضية للرسوم القديمة بلا رابط
     */
    private function categoryForFee(?StudentFee $fee): string
    {
        if ($fee?->feePlan) {
            return $fee->feePlan->frequency === 'yearly'
                ? CashTransaction::CATEGORY_REGISTRATION_FEE
                : CashTransaction::CATEGORY_MONTHLY_FEE;
        }

        if ($fee?->feeType) {
            return $fee->feeType->resolveLedgerCategory();
        }

        return CashTransaction::CATEGORY_MONTHLY_FEE;
    }
}
