<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$isDryRun = in_array('--dry-run', $argv, true);

echo "=================================================================\n";
echo "    COMPLEXE LA PROVIDENCE - EMPLOYEE ADVANCES MIGRATION SCRIPT  \n";
echo "=================================================================\n";
echo "Mode: " . ($isDryRun ? "🔍 DRY-RUN (معاينة فقط دون تعديل قاعدة البيانات)" : "⚡ LIVE EXECUTION (تنفيذ فعلي)") . "\n\n";

$jsonPath = '/tmp/old_advances.json';
if (!file_exists($jsonPath)) {
    echo "❌ خطأ: ملف البيانات المستخرجة غير موجود في {$jsonPath}!\n";
    exit(1);
}

$scrapedData = json_decode(file_get_contents($jsonPath), true);
if (!is_array($scrapedData)) {
    echo "❌ خطأ في قراءة ملف JSON!\n";
    exit(1);
}

echo "عدد العمليات في الملف: " . count($scrapedData) . " عملية.\n\n";

// 1. خريطة السنوات الدراسية
$years = AcademicYear::all()->keyBy('id');
$year2026_2027 = AcademicYear::where('name', 'like', '%2026-2027%')->first()
    ?? AcademicYear::where('is_active', true)->first();
$year2025_2026 = AcademicYear::where('name', 'like', '%2025-2026%')->first();

if (!$year2026_2027 || !$year2025_2026) {
    echo "❌ خطأ في العثور على السنوات الدراسية في النظام!\n";
    exit(1);
}

echo "خريطة السنوات:\n";
echo " - '2027/2026' ➔ [ID: {$year2026_2027->id}] {$year2026_2027->name}\n";
echo " - '2026/2025' ➔ [ID: {$year2025_2026->id}] {$year2025_2026->name}\n\n";

// 2. خريطة الموظفين
$employees = Employee::all();
$empMap = [];

foreach ($employees as $emp) {
    $fullName = trim($emp->first_name . ' ' . $emp->last_name);
    $empMap[$fullName] = $emp->id;
    // أيضاً العكس إذا لزم
    $empMap[trim($emp->last_name . ' ' . $emp->first_name)] = $emp->id;
}

// استثناءات خاصة بالأسماء وتعديلات همزات/أل التعريف
$customAliases = [
    'خالد رابحي' => 'خالد الرابحي',
    'علي الرابحي' => 'علي الرابحي',
];

// التحقق من كافة الأسماء
$missingEmployees = [];
foreach ($scrapedData as $adv) {
    $rawName = trim($adv['employee_name']);
    $lookupName = $customAliases[$rawName] ?? $rawName;
    if (!isset($empMap[$lookupName])) {
        // فحص بالبحث المباشر
        $found = Employee::whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$lookupName])
            ->orWhereRaw("CONCAT(last_name, ' ', first_name) = ?", [$lookupName])
            ->first();
        if ($found) {
            $empMap[$rawName] = $found->id;
        } else {
            $missingEmployees[$rawName] = true;
        }
    } else {
        $empMap[$rawName] = $empMap[$lookupName];
    }
}

if (!empty($missingEmployees)) {
    echo "❌ موظفون مفقودون لم يتم مطابقتهم:\n";
    print_r(array_keys($missingEmployees));
    exit(1);
}

echo "✅ تم التحقق من مطابقة كافة الموظفين بنسبة 100%.\n\n";

$adminUser = User::whereHas('role', fn($q) => $q->where('name', 'admin'))->first() ?? User::first();
$ledgerService = app(LedgerService::class);

$totalAdvancesAmount = 0;
$totalRepaidAmount = 0;
$totalRepaymentsCount = 0;

$importedAdvances = 0;
$importedRepayments = 0;

DB::beginTransaction();

try {
    foreach ($scrapedData as $item) {
        $empId = $empMap[trim($item['employee_name'])];
        $oldYearStr = trim($item['academic_year']);
        $academicYear = ($oldYearStr === '2027/2026') ? $year2026_2027 : $year2025_2026;
        $isCurrentYear = ($academicYear->id === $year2026_2027->id);

        $amount = (float) $item['amount'];
        $advanceDate = Carbon::parse($item['advance_date'])->toDateString();
        $repayments = $item['repayments'] ?? [];
        $repaidAmount = (float) $item['repaid_amount'];

        $totalAdvancesAmount += $amount;
        $totalRepaidAmount += $repaidAmount;
        $totalRepaymentsCount += count($repayments);

        // الحالة
        if ($repaidAmount >= $amount && $amount > 0) {
            $status = EmployeeAdvance::STATUS_SETTLED;
        } elseif ($repaidAmount > 0) {
            $status = EmployeeAdvance::STATUS_PARTIAL;
        } else {
            $status = EmployeeAdvance::STATUS_PENDING;
        }

        // النوع: سلفة للجميع بناءً على رغبة المالك
        $type = EmployeeAdvance::TYPE_LOAN;

        $installmentCount = max(1, count($repayments));
        $dueDate = Carbon::parse($advanceDate)->addMonths($installmentCount)->toDateString();

        $advance = new EmployeeAdvance([
            'employee_id' => $empId,
            'academic_year_id' => $academicYear->id,
            'type' => $type,
            'amount' => $amount,
            'settled_amount' => $repaidAmount,
            'advance_date' => $advanceDate,
            'due_date' => $dueDate,
            'installment_count' => $installmentCount,
            'repayment_method' => 'salary_deduction',
            'purpose' => "منقولة من المنصة القديمة (سلفة #{$item['old_id']})",
            'reason' => "سلفة منقولة من المنصة القديمة",
            'method' => 'cash',
            'status' => $status,
            'is_opening' => !$isCurrentYear, // عمليات السنة السابقة وسم رصيد افتتاحي لحماية الخزينة
            'created_by' => $adminUser->id,
        ]);

        $advance->created_at = Carbon::parse($advanceDate);
        $advance->updated_at = Carbon::now();

        if (!$isDryRun) {
            $advance->save();

            // حفظ الدفعات
            foreach ($repayments as $rep) {
                $repAmount = (float) $rep['amount'];
                $paidAt = !empty($rep['paid_at']) 
                    ? Carbon::parse($rep['paid_at'])->toDateString()
                    : Carbon::parse($rep['created_at'])->toDateString();

                $method = !empty($rep['salary_id']) 
                    ? EmployeeAdvanceRepayment::METHOD_SALARY_DEDUCTION 
                    : EmployeeAdvanceRepayment::METHOD_CASH;

                $repaymentModel = new EmployeeAdvanceRepayment([
                    'employee_advance_id' => $advance->id,
                    'employee_id' => $empId,
                    'academic_year_id' => $academicYear->id,
                    'amount' => number_format($repAmount, 2, '.', ''),
                    'repaid_at' => $paidAt,
                    'method' => $method,
                    'notes' => "دفعة منقولة من المنصة القديمة (معرف #{$rep['id']})",
                    'created_by' => $adminUser->id,
                ]);
                $repaymentModel->created_at = Carbon::parse($paidAt);
                $repaymentModel->updated_at = Carbon::now();
                $repaymentModel->save();

                // إسقاط أثر الخزينة للسنة الحالية فقط إن كانت نقدية
                if ($isCurrentYear && $method === EmployeeAdvanceRepayment::METHOD_CASH) {
                    $ledgerService->recordAdvanceRepayment($repaymentModel);
                }

                $importedRepayments++;
            }

            // إسقاط أثر الخزينة للسنة الحالية (خروج نقد)
            if ($isCurrentYear) {
                $ledgerService->recordEmployeeAdvance($advance);
            }

            $advance->recalculateSettlement();
        }

        $importedAdvances++;
    }

    if ($isDryRun) {
        DB::rollBack();
        echo "🔍 [DRY-RUN] تم تدقيق كافة السجلات بنجاح دون أي خطأ!\n";
    } else {
        DB::commit();
        echo "⚡ [SUCCESS] تم حفظ ونقل كافة السجلات وتثبيتها في قاعدة البيانات بنجاح!\n";
    }

} catch (\Throwable $e) {
    DB::rollBack();
    echo "❌ خطأ أثناء النقل: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n--- الإحصائيات الإجمالية للعملية ---\n";
echo "إجمالي السلفات والتسبقات: {$importedAdvances} عملية\n";
echo "إجمالي دفعات الاسترجاع: " . ($isDryRun ? $totalRepaymentsCount : $importedRepayments) . " دفعة\n";
echo "إجمالي المبالغ الممنوحة: " . number_format($totalAdvancesAmount, 2) . " د.ت\n";
echo "إجمالي المبالغ المسترجعة: " . number_format($totalRepaidAmount, 2) . " د.ت\n";
echo "صافي المتبقي بذمة الموظفين: " . number_format($totalAdvancesAmount - $totalRepaidAmount, 2) . " د.ت\n\n";

echo "=================================================================\n";
echo "                     انتهت عملية النقل بنجاح!                    \n";
echo "=================================================================\n";
