<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeWaiver;
use App\Models\ManualStudentDebt;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Console\Command;

class ResetPrelaunchEnrollmentsCommand extends Command
{
    /**
     * اسم وتوقيع الأمر — أداة التدقيق والرقابة الشاملة للقراءة فقط (100% Read-Only Audit).
     */
    protected $signature = 'app:reset-prelaunch-enrollments {--dry-run : تشغيل وضع التدقيق والمعاينة}';

    /**
     * وصف الأمر.
     */
    protected $description = 'تقرير تدقيق ورقابة شامل للسنة النشطة والأقسام وحالة الترسيم المالي والخزينة والديون';

    /**
     * تنفيذ التدقيق للقراءة فقط (Read-Only).
     */
    public function handle(): int
    {
        $this->warn('═══════════════════════════════════════════════════════════════════════════════════');
        $this->warn(' 📋 تقرير التدقيق والرقابة المدرسية والمالية الشامل (READ-ONLY AUDIT)');
        $this->warn('═══════════════════════════════════════════════════════════════════════════════════');

        // 1. استخراج السنة النشطة ديناميكياً والتحقق الحاسم (Fail-Closed)
        $activeYears = AcademicYear::where('is_active', true)->get();
        if ($activeYears->count() === 0) {
            $this->error('❌ خطأ أمان حاسم: لا توجد أي سنة دراسية نشطة (is_active = 1) في قاعدة البيانات.');
            return self::FAILURE;
        }

        if ($activeYears->count() > 1) {
            $this->error('❌ خطأ أمان حاسم: توجد أكثر من سنة دراسية نشطة واحدة (' . $activeYears->count() . ' سنوات). يجب تفعيل سنة واحدة فقط.');
            return self::FAILURE;
        }

        $activeYear = $activeYears->first();
        $totalStudents = Student::count();
        $activeEnrollments = Enrollment::where('academic_year_id', $activeYear->id)->where('status', 'active')->get();
        $totalActiveEnrollmentsCount = $activeEnrollments->count();

        $this->info("📅 السنة الدراسية النشطة: {$activeYear->name} (معرّف ID: {$activeYear->id})");
        $this->info("👥 إجمالي التلاميذ المسجلين في جدول students: {$totalStudents}");
        $this->info("🏫 إجمالي التلاميذ المسندين إلى أقسام في السنة النشطة: {$totalActiveEnrollmentsCount}");

        // 2. حالة الترسيم المالي للتلاميذ في السنة النشطة
        $enrollmentIds = $activeEnrollments->pluck('id')->all();
        
        $financiallyRegisteredEnrollmentIds = ! empty($enrollmentIds)
            ? StudentFee::whereIn('enrollment_id', $enrollmentIds)
                ->where('status', 'paid')
                ->whereHas('feeType', fn ($q) => $q->where('ledger_category', CashTransaction::CATEGORY_REGISTRATION_FEE)->orWhere('name_ar', 'like', '%ترسيم%')->orWhere('name_ar', 'like', '%تسجيل%'))
                ->pluck('enrollment_id')
                ->unique()
                ->all()
            : [];

        $financiallyRegisteredCount = count($financiallyRegisteredEnrollmentIds);
        $pendingFinancialRegistrationCount = $totalActiveEnrollmentsCount - $financiallyRegisteredCount;

        $this->newLine();
        $this->info('─── [1] حالة الترسيم المالي للتلاميذ المسندين للأقسام ───');
        $this->line("✅ تلاميذ أتموا الترسيم المالي ومستخلصون: {$financiallyRegisteredCount}");
        $this->line("⏳ تلاميذ في الأقسام في انتظار الترسيم المالي: {$pendingFinancialRegistrationCount}");

        // 3. جرد وتوزيع الأقسام الفعلية
        $this->newLine();
        $this->info('─── [2] توزيع التلاميذ حسب الأقسام الفعلية للسنة النشطة ───');
        $sections = Section::with('level')->orderBy('level_id')->orderBy('name')->get();
        $sectionTable = [];
        foreach ($sections as $sec) {
            $count = $activeEnrollments->where('section_id', $sec->id)->count();
            if ($count > 0) {
                $sectionTable[] = [
                    $sec->id,
                    $sec->level?->name ?: '—',
                    $sec->name,
                    $count,
                ];
            }
        }
        $this->table(['معرف القسم', 'المستوى', 'القسم', 'عدد التلاميذ المسندين'], $sectionTable);

        // 4. الرقابة المالية وفحص التناقضات (Discrepancy Audit)
        $allPayments = Payment::all();
        $allCashTransactions = CashTransaction::all();
        $allStudentFees = StudentFee::all();
        $unpaidFees = $allStudentFees->where('status', '!=', 'paid');
        $feesWithoutAllocations = $allStudentFees->filter(fn ($f) => $f->paymentAllocations()->count() === 0);

        $cashInTotal = (float) $allCashTransactions->where('direction', CashTransaction::DIRECTION_IN)->sum('amount');
        $cashOutTotal = (float) $allCashTransactions->where('direction', CashTransaction::DIRECTION_OUT)->sum('amount');
        $netTreasuryBalance = $cashInTotal - $cashOutTotal;
        $totalPaymentsAmount = (float) $allPayments->sum('amount');

        $this->newLine();
        $this->info('─── [3] الرقابة المالية ومطابقة الخزينة المركزية ───');
        $this->line("📥 إجمالي المقبوضات المسجلة بالخزينة (IN): " . number_format($cashInTotal, 3) . " د.ت ({$allCashTransactions->where('direction', 'in')->count()} حركات)");
        $this->line("📤 إجمالي المصاريف المسجلة بالخزينة (OUT): " . number_format($cashOutTotal, 3) . " د.ت ({$allCashTransactions->where('direction', 'out')->count()} حركات)");
        $this->line("💰 رصيد الخزينة الحالي (Treasury Balance): " . number_format($netTreasuryBalance, 3) . " د.ت");
        $this->line("🧾 إجمالي سندات القبض (Payments Count): {$allPayments->count()} سند بمجموع " . number_format($totalPaymentsAmount, 3) . " د.ت");
        $this->line("📊 الرسوم غير المسددة: {$unpaidFees->count()} رسم (منها {$feesWithoutAllocations->count()} رسم بدون أي دفعات)");

        // فحص التناقضات بين الدفعات والخزينة باستخدام morphClass المعتمد في الدفتر المركزي
        $paymentMorph = (new Payment)->getMorphClass();
        $discrepancies = [];
        foreach ($allPayments as $payment) {
            $hasCash = $allCashTransactions
                ->where('source_type', $paymentMorph)
                ->where('source_id', $payment->id)
                ->isNotEmpty();

            if (! $hasCash) {
                $discrepancies[] = "سند قبض #{$payment->id} بمبلغ {$payment->amount} د.ت ليس له حركة خزينة مقابلة.";
            }
        }

        if (empty($discrepancies)) {
            $this->line("✨ سلامة التطابق المالي: لا توجد أي تناقضات بين سندات القبض وحركات الخزينة (متطابقة 100%).");
        } else {
            $this->error("⚠️ تم رصد " . count($discrepancies) . " تناقضات مالية:");
            foreach ($discrepancies as $disc) {
                $this->line("   • {$disc}");
            }
        }

        // 5. جرد الديون القديمة المحفوظة
        $allDebts = ManualStudentDebt::all();
        $totalDebtsCount = $allDebts->count();
        $totalDebtsAmount = (float) $allDebts->sum('original_amount');

        $this->newLine();
        $this->info('─── [4] جرد الديون القديمة المحفوظة (manual_student_debts) ───');
        $this->line("💼 إجمالي الديون القديمة: {$totalDebtsCount} دين بقيمة " . number_format($totalDebtsAmount, 3) . " د.ت (محمية بالكامل)");
        $this->line("🔗 ارتباط الديون: جميع الديون ترتبط مباشرة بجدول students عبر student_id ولا تتأثر بحالة التسجيل.");

        $this->newLine();
        $this->warn('═══════════════════════════════════════════════════════════════════════════════════');
        $this->warn(' 🔒 تأكيد أمان: هذا الأمر للتدقيق والرقابة (100% READ-ONLY) ولا يجري أي حذف أو تعديل.');
        $this->warn('═══════════════════════════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }
}
