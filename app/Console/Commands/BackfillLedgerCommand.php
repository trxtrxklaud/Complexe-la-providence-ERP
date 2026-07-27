<?php

namespace App\Console\Commands;

use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Salary;
use App\Models\TreasuryWithdrawal;
use App\Services\LedgerService;
use Illuminate\Console\Command;

/**
 * تعبئة رجعية للدفتر النقدي المركزي.
 *
 * المستندات المسجَّلة قبل إدخال cash_transactions لا أثر نقدي لها، فتظهر التقارير ناقصة.
 * هذا الأمر يُعيد إسقاط كل مستند في الدفتر. الإسقاط idempotent (updateOrCreate على
 * source_type/source_id/category) فتكرار تشغيل الأمر لا يُضاعف أي مبلغ.
 */
class BackfillLedgerCommand extends Command
{
    protected $signature = 'ledger:backfill {--dry-run : عرض الأعداد فقط دون كتابة}';

    protected $description = 'إعادة إسقاط الدفعات والرواتب والمصاريف والسلف والسحوبات في الدفتر النقدي المركزي';

    public function handle(LedgerService $ledger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $targets = [
            'الدفعات'  => [Payment::class, 'recordPayment', ['paymentAllocations.studentFee.feePlan', 'paymentAllocations.studentFee.feeType', 'enrollment']],
            'الرواتب'  => [Salary::class, 'recordSalary', ['employee']],
            'المصاريف' => [Expense::class, 'recordExpense', []],
            'السلف'    => [EmployeeAdvance::class, 'recordEmployeeAdvance', ['employee']],
            'السحوبات' => [TreasuryWithdrawal::class, 'recordWithdrawal', []],
        ];

        foreach ($targets as $label => [$model, $method, $relations]) {
            $total = $model::count();

            if ($dryRun) {
                $this->line(sprintf('%s: %d مستند (عرض فقط)', $label, $total));
                continue;
            }

            $done = 0;
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $model::with($relations)->chunkById(200, function ($rows) use ($ledger, $method, &$done, $bar) {
                foreach ($rows as $row) {
                    $ledger->{$method}($row);
                    $done++;
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
            $this->info(sprintf('%s: تم إسقاط %d مستند', $label, $done));
        }

        $this->newLine();
        $this->info('انتهت التعبئة الرجعية. التقارير الآن تقرأ سجلاً كاملاً.');

        return self::SUCCESS;
    }
}
