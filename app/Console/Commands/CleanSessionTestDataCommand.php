<?php

namespace App\Console\Commands;

use App\Models\CashTransaction;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\EmployeeLiability;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeWaiver;
use App\Models\Guardian;
use App\Models\ManualStudentDebt;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanSessionTestDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-test-data '
        . '{--since=2026-08-30 : تاريخ بداية البيانات التجريبية المراد تنظيفها (افتراضياً 2026-08-30)} '
        . '{--clean-mustapha-debt : تنظيف ديون وتسبيقات الإطار مصطفى العبدولي} '
        . '{--force : تنفيذ الحذف والتنظيف الفعلي بدل العرض فقط}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'تنظيف بيانات التجارب الأخيرة (التلاميذ والمدفوعات التجريبية وديون مصطفى العبدولي) مع الحفاظ الكامل على ديون التلاميذ القديمة';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sinceDate = $this->option('since') ?: '2026-08-30';
        $sinceDateTime = $sinceDate . ' 00:00:00';
        $force = (bool) $this->option('force');

        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->warn(' تنظيف بيانات التجارب مع الحفاظ على ديون التلاميذ القديمة');
        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->info("التاريخ المحدد لبداية بيانات التجارب: {$sinceDate}");
        $this->info("الوضع: " . ($force ? "تنفيذ فعلي (--force)" : "معاينة فقط (Dry-Run)"));
        $this->newLine();

        // 1. العثور على التلاميذ التجريبيين المسجلين منذ التاريخ المحدد
        $testStudents = Student::query()
            ->where('created_at', '>=', $sinceDateTime)
            ->get();

        $this->info("1. التلاميذ التجريبيون المسجلون منذ {$sinceDate} (العدد: " . $testStudents->count() . "):");
        foreach ($testStudents as $s) {
            $this->line("   • تلميذ #{$s->id}: {$s->first_name} {$s->last_name} ({$s->student_code}) — مسجل بتاريخ: {$s->created_at}");
        }

        // 2. المدفوعات المسجلة منذ التاريخ المحدد
        $testPayments = Payment::query()
            ->where('created_at', '>=', $sinceDateTime)
            ->orWhere('payment_date', '>=', $sinceDate)
            ->get();

        $this->newLine();
        $this->info("2. المقبوضات/المدفوعات المسجلة منذ {$sinceDate} (العدد: " . $testPayments->count() . "):");
        foreach ($testPayments as $p) {
            $studentName = $p->student ? "{$p->student->first_name} {$p->student->last_name}" : "غير محدد";
            $this->line("   • دفعة #{$p->id}: مبلغ {$p->amount} د.ت — تلميذ: {$studentName} — تاريخ: {$p->payment_date} ({$p->created_at})");
        }

        // 3. الإطار مصطفى العبدولي
        $mustapha = Employee::query()
            ->where(function ($q) {
                $q->where('first_name', 'LIKE', '%مصطفى%')
                    ->orWhere('last_name', 'LIKE', '%عبدولي%')
                    ->orWhere('first_name', 'LIKE', '%mustapha%')
                    ->orWhere('last_name', 'LIKE', '%abdouli%');
            })
            ->first();

        $this->newLine();
        $this->info("3. ديون وتسبيقات الإطار مصطفى العبدولي:");
        if ($mustapha) {
            $this->line("   • تم العثور على الإطار: #{$mustapha->id} — {$mustapha->first_name} {$mustapha->last_name}");
            
            // التسبيقات
            $advCount = EmployeeAdvance::where('employee_id', $mustapha->id)->count();
            $advRepCount = EmployeeAdvanceRepayment::where('employee_id', $mustapha->id)->count();
            $liabCount = EmployeeLiability::where('employee_id', $mustapha->id)->count();
            
            $openingDebtsCount = 0;
            if (Schema::hasTable('employee_opening_debts')) {
                $openingDebtsCount = DB::table('employee_opening_debts')->where('employee_id', $mustapha->id)->count();
            }
            if (Schema::hasTable('old_employee_debts')) {
                $openingDebtsCount += DB::table('old_employee_debts')->where('employee_id', $mustapha->id)->count();
            }

            $this->line("   • تسبيقات (Advances): {$advCount} | استرجاعات: {$advRepCount} | التزامات: {$liabCount} | ديون افتتاحية: {$openingDebtsCount}");
        } else {
            $this->line("   • لم يتم العثور على موظف باسم مصطفى العبدولي في قاعدة البيانات.");
        }

        // 4. ديون التلاميذ القديمة (المحمية)
        $manualDebtsCount = ManualStudentDebt::count();
        $this->newLine();
        $this->info("4. ديون التلاميذ القديمة المحمية (manual_student_debts):");
        $this->line("   • إجمالي الديون القديمة المحمية: {$manualDebtsCount} دين (لن يتم حذف أي منها نهائياً).");

        if (! $force) {
            $this->newLine();
            $this->warn('لم يتم تعديل أو حذف أي سجل. لإتمام التنظيف الفعلي أضف الخيار: --force');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('بدء تنفيذ عملية التنظيف الفعلية داخل Transaction آمنة...');

        $deletedCounts = [
            'test_students' => 0,
            'test_enrollments' => 0,
            'test_payments' => 0,
            'test_allocations' => 0,
            'test_student_fees' => 0,
            'test_club_fees' => 0,
            'test_cash_transactions' => 0,
            'restored_manual_debts' => 0,
            'mustapha_debts_cleared' => 0,
        ];

        DB::transaction(function () use ($sinceDateTime, $sinceDate, $mustapha, &$deletedCounts) {
            // أ. إعادة ضبط ديون التلاميذ القديمة التي تم استخلاصها في التجارب الحديثة
            $allocsOnManualDebts = PaymentAllocation::query()
                ->whereHas('studentFee', function ($q) {
                    $q->whereNotNull('manual_student_debt_id')
                      ->orWhere('description', 'LIKE', '%دَين قديم%');
                })
                ->whereHas('payment', function ($q) use ($sinceDateTime, $sinceDate) {
                    $q->where('created_at', '>=', $sinceDateTime)
                      ->orWhere('payment_date', '>=', $sinceDate);
                })
                ->get();

            foreach ($allocsOnManualDebts as $alloc) {
                $sFee = $alloc->studentFee;
                if ($sFee) {
                    if ($sFee->manual_student_debt_id) {
                        $mDebt = ManualStudentDebt::find($sFee->manual_student_debt_id);
                        if ($mDebt) {
                            $mDebt->update([
                                'amount_paid' => 0.00,
                                'status' => 'unpaid',
                            ]);
                            $deletedCounts['restored_manual_debts']++;
                        }
                    }
                    $sFee->update([
                        'amount_paid' => 0.00,
                        'status' => 'unpaid',
                    ]);
                }
            }

            // ب. حذف مقبوضات التجارب وحركاتها المالية في الخزينة
            $testPayments = Payment::query()
                ->where('created_at', '>=', $sinceDateTime)
                ->orWhere('payment_date', '>=', $sinceDate)
                ->get();

            foreach ($testPayments as $payment) {
                // حذف حركات الخزينة المرتبطة بالدفعة
                $cashDeleted = CashTransaction::where('source_type', $payment->getMorphClass())
                    ->where('source_id', $payment->id)
                    ->delete();
                $deletedCounts['test_cash_transactions'] += $cashDeleted;

                // حذف توزيعات الدفعة
                $allocDeleted = PaymentAllocation::where('payment_id', $payment->id)->delete();
                $deletedCounts['test_allocations'] += $allocDeleted;

                $payment->delete();
                $deletedCounts['test_payments']++;
            }

            // ج. حذف التلاميذ التجريبيين الجدد مع كافة متعلقاتهم
            $testStudents = Student::query()
                ->where('created_at', '>=', $sinceDateTime)
                ->get();

            foreach ($testStudents as $student) {
                $enrollmentIds = Enrollment::where('student_id', $student->id)->pluck('id')->all();

                // 1. نوادي
                ClubMonthlyDiscount::whereIn('enrollment_id', $enrollmentIds)->delete();
                ClubMonthlyFee::where('student_id', $student->id)->orWhereIn('enrollment_id', $enrollmentIds)->delete();
                ClubSubscription::where('student_id', $student->id)->orWhereIn('enrollment_id', $enrollmentIds)->delete();

                // 2. تخفيضات وإعفاءات
                MonthlyDiscount::whereIn('enrollment_id', $enrollmentIds)->delete();
                EnrollmentDiscount::whereIn('enrollment_id', $enrollmentIds)->delete();
                FeeWaiver::where('student_id', $student->id)->orWhereIn('enrollment_id', $enrollmentIds)->delete();

                // 3. معاليم التلميذ
                StudentFee::where('student_id', $student->id)->orWhereIn('enrollment_id', $enrollmentIds)->delete();

                // 4. التسجيلات
                Enrollment::where('student_id', $student->id)->delete();
                $deletedCounts['test_enrollments'] += count($enrollmentIds);

                // 5. فك ارتباط الأولياء
                $guardianIds = $student->guardians()->pluck('guardians.id')->all();
                $student->guardians()->detach();
                foreach ($guardianIds as $gId) {
                    $g = Guardian::find($gId);
                    if ($g && $g->students()->count() === 0 && $g->created_at >= $sinceDateTime) {
                        $g->delete();
                    }
                }

                $student->delete();
                $deletedCounts['test_students']++;
            }

            // د. تصفير معاليم النوادي غير التجريبية التي تم استخلاصها في التجارب الحديثة
            ClubMonthlyFee::query()
                ->where('created_at', '>=', $sinceDateTime)
                ->where('status', '!=', ClubMonthlyFee::STATUS_UNPAID)
                ->update([
                    'amount_paid' => 0.00,
                    'status' => ClubMonthlyFee::STATUS_UNPAID,
                ]);

            // هـ. تنظيف ديون وتسبيقات الإطار مصطفى العبدولي
            if ($mustapha) {
                // 1. تسبيقات واسترجاعاتها
                $advIds = EmployeeAdvance::where('employee_id', $mustapha->id)->pluck('id')->all();
                if ($advIds) {
                    CashTransaction::where('source_type', (new EmployeeAdvance)->getMorphClass())
                        ->whereIn('source_id', $advIds)
                        ->delete();
                    EmployeeAdvanceRepayment::whereIn('employee_advance_id', $advIds)->delete();
                    EmployeeAdvance::whereIn('id', $advIds)->delete();
                    $deletedCounts['mustapha_debts_cleared'] += count($advIds);
                }

                // 2. استرجاعات مباشرة
                $repIds = EmployeeAdvanceRepayment::where('employee_id', $mustapha->id)->pluck('id')->all();
                if ($repIds) {
                    CashTransaction::where('source_type', (new EmployeeAdvanceRepayment)->getMorphClass())
                        ->whereIn('source_id', $repIds)
                        ->delete();
                    EmployeeAdvanceRepayment::whereIn('id', $repIds)->delete();
                }

                // 3. التزامات
                EmployeeLiability::where('employee_id', $mustapha->id)->delete();

                // 4. ديون افتتاحية للموظف
                if (Schema::hasTable('employee_opening_debts')) {
                    $eOpIds = DB::table('employee_opening_debts')->where('employee_id', $mustapha->id)->pluck('id')->all();
                    if ($eOpIds && Schema::hasTable('employee_opening_debt_collections')) {
                        DB::table('employee_opening_debt_collections')->whereIn('employee_opening_debt_id', $eOpIds)->delete();
                    }
                    DB::table('employee_opening_debts')->where('employee_id', $mustapha->id)->delete();
                }

                if (Schema::hasTable('old_employee_debts')) {
                    DB::table('old_employee_debts')->where('employee_id', $mustapha->id)->delete();
                }
            }

            // و. تنظيف أي حركات خزينة يتيمة لا أصل لها
            CashTransaction::query()
                ->where('created_at', '>=', $sinceDateTime)
                ->where('source_type', (new Payment)->getMorphClass())
                ->whereNotIn('source_id', Payment::pluck('id'))
                ->delete();
        });

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' تقرير تنظيف بيانات التجارب بنجاح');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line(" • عدد التلاميذ التجريبيين المحذوفين: {$deletedCounts['test_students']}");
        $this->line(" • عدد التسجيلات المحذوفة: {$deletedCounts['test_enrollments']}");
        $this->line(" • عدد الدفعات التجريبية المحذوفة: {$deletedCounts['test_payments']}");
        $this->line(" • عدد حركات الخزينة المنظفة: {$deletedCounts['test_cash_transactions']}");
        $this->line(" • عدد ديون التلاميذ القديمة المستعادة بالكامل: {$deletedCounts['restored_manual_debts']}");
        $this->line(" • تنظيف ديون وتسبيقات مصطفى العبدولي: " . ($mustapha ? "تم بنجاح" : "لم توجد"));
        $this->info(" • ديون التلاميذ القديمة (Manual Student Debts): " . ManualStudentDebt::count() . " دين سليم ومحفوظ.");

        return self::SUCCESS;
    }
}
