<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomRosterController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubMonthlyDiscountController;
use App\Http\Controllers\ClubReportController;
use App\Http\Controllers\ClubSubscriptionController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\EmployeeAdvanceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeHoursController;
use App\Http\Controllers\ExemptionController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FeeTypeController;
use App\Http\Controllers\FeeWaiverController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\ManualDebtController;
use App\Http\Controllers\MonthlyDiscountController;
use App\Http\Controllers\OldEmployeeDebtController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\TreasuryDaybookController;
use App\Http\Controllers\TreasuryWithdrawalController;
use App\Http\Controllers\UnpaidMonthlyReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionOverrideController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->post('/login', [AuthController::class, 'login']);

// كل المسارات المصادَق عليها تمرّ بـ active فيمنع أي حساب معطَل من الوصول.
Route::middleware(['auth:sanctum', 'active', 'throttle:120,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // قائمة السنوات الدراسية — قراءة فقط تُستعمل في عدة شاشات، متاحة لأي مستخدِم مُفعّل.
    Route::get('/academic-years', [AcademicYearController::class, 'index']);

    // الموظفون — صلاحية منفصلة
    Route::middleware('permission:manage_employees')->group(function () {
        // جدول الحصص: يحسب ويقترح فحسب — لا يُنشئ راتباً ولا صفّاً نقدياً.
        Route::get('/employees/{employee}/hours/monthly-summary', [EmployeeHoursController::class, 'monthlySummary']);
        Route::get('/employees/{employee}/hours', [EmployeeHoursController::class, 'index']);
        Route::post('/employees/{employee}/hours', [EmployeeHoursController::class, 'store']);

        Route::apiResource('/employees', EmployeeController::class);
    });

    // الرواتب — صلاحية منفصلة (الحذف النهائي ممنوع، يُستبدل بإلغاء موثّق)
    Route::middleware('permission:manage_salaries')->group(function () {
        Route::apiResource('/salaries', SalaryController::class)->except(['destroy']);
        Route::post('/salaries/{salary}/cancel', [SalaryController::class, 'cancel']);

        // سلف الإطارات — التزام تجاه الإطار، فتتبع صلاحية الرواتب
        Route::apiResource('/employee-advances', EmployeeAdvanceController::class)
            ->except(['destroy'])
            ->parameters(['employee-advances' => 'advance']);
        Route::post('/employee-advances/{advance}/settle', [EmployeeAdvanceController::class, 'settle']);
        Route::post('/employee-advances/{advance}/cancel', [EmployeeAdvanceController::class, 'cancel']);

        // ردّيات السلفة: سجل० مأرخ لكل دفعة، وإلغاء موثّق لردّ مفرد
        // دون المساس ببقية الردّيات ولا بالسلفة نفسها.
        Route::get('/employee-advances/{advance}/repayments', [EmployeeAdvanceController::class, 'repayments']);
        Route::post('/advance-repayments/{repayment}/cancel', [EmployeeAdvanceController::class, 'cancelRepayment']);
    });

    // المصاريف وأصنافها — صلاحية مستقلة
    Route::middleware('permission:manage_expenses')->group(function () {
        Route::apiResource('/expense-categories', ExpenseCategoryController::class);
        Route::apiResource('/expenses', ExpenseController::class)->except(['destroy']);
        Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel']);
    });

    // الخزينة — السجل० والرصيد والسحوبات
    Route::middleware('permission:manage_treasury')->group(function () {
        Route::get('/treasury/history', [TreasuryController::class, 'history']);
        Route::get('/treasury/balance', [TreasuryController::class, 'balance']);

        Route::apiResource('/treasury/withdrawals', TreasuryWithdrawalController::class)->except(['destroy']);
        Route::post('/treasury/withdrawals/{withdrawal}/cancel', [TreasuryWithdrawalController::class, 'cancel']);

        // إقفال سنة دراسية وترحيل متخلّداتها كأرصدة افتتاحية — عملية مالية حساسة
        // فتتبع صلاحية الخزينة ولا يملكها القابض.
        Route::post('/academic-years/{year}/close', [AcademicYearController::class, 'close']);

        // الأرصدة الافتتاحية اليدوية (بيانات خارجية بلا أثر سابق في النظام):
        // الديون القديمة للتلاميذ. إدخالها لا يحرّك مالاً
        // — تحصيلها لاحقاً عبر مسار الاستخلاص (prior_year_debt)
        // فتتبع صلاحية الخزينة وحدها.
        Route::get('/manual-debts/bulk-options', [ManualDebtController::class, 'bulkOptions']);
        Route::get('/manual-debts/section-students', [ManualDebtController::class, 'sectionStudents']);
        Route::post('/manual-debts/bulk', [ManualDebtController::class, 'bulkStore']);
        Route::get('/manual-debts', [ManualDebtController::class, 'index']);
        Route::post('/manual-debts', [ManualDebtController::class, 'store']);
        Route::get('/manual-debts/{debt}', [ManualDebtController::class, 'show']);
        Route::post('/manual-debts/{debt}/cancel', [ManualDebtController::class, 'cancel']);

        // الأرصدة الافتتاحية التاريخية للإطارات: ديون قديمة مدخلة يدوياً
        Route::get('/employee-opening-debts', [OldEmployeeDebtController::class, 'index']);
        Route::post('/employee-opening-debts', [OldEmployeeDebtController::class, 'store']);
        Route::get('/employee-opening-debts/{debt}', [OldEmployeeDebtController::class, 'show']);
        Route::put('/employee-opening-debts/{debt}', [OldEmployeeDebtController::class, 'update']);
        Route::post('/employee-opening-debts/{debt}/cancel', [OldEmployeeDebtController::class, 'cancel']);
        Route::post('/employee-opening-debts/{debt}/collect', [OldEmployeeDebtController::class, 'collect']);
    });

    // التقارير المالية — قراءة فقط، وكلها تُبنى على الدفتر النقدي المركزي
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports/net-income', [FinancialReportController::class, 'netIncome']);

        // الدخل الصافي مجمّعاً: granularity=month|year
        // مسار مستقل لا معامِل داخل net-income، حتّى لا يبتلع مسارٌ ذو {parameter}
        // قيمة ثابتة مثل periods ويصعب تشخيصه لاحقاً.
        Route::get('/reports/net-income/periods', [FinancialReportController::class, 'netIncomePeriods']);

        // كشف الخزينة اليومي: من تاريخ مختار إلى اليوم، بطاقة لكل يوم مع التراكمي.
        Route::get('/reports/treasury-daybook', [TreasuryDaybookController::class, 'index']);

        Route::get('/reports/income-by-date', [FinancialReportController::class, 'incomeByDate']);
        Route::get('/reports/expenses', [FinancialReportController::class, 'expenses']);
        Route::get('/reports/revenue/students', [FinancialReportController::class, 'revenueByStudent']);
        Route::get('/reports/revenue/classrooms', [FinancialReportController::class, 'revenueByClassroom']);
        Route::get('/reports/revenue/years', [FinancialReportController::class, 'revenueByYear']);
        Route::get('/reports/unpaid-monthly/options', [UnpaidMonthlyReportController::class, 'options']);
        Route::get('/reports/unpaid-monthly', [UnpaidMonthlyReportController::class, 'index']);

        // كشف مداخيل القسم: كل تلاميذ القسم أبجدياً مع المتخلَّد بالذمّة.
        // مسار options يُعلَن قبل مسار {section} لأن مساراً بمعامِل يبتلع أي قيمة ثابتة بعده.
        Route::get('/reports/classroom-roster/options', [ClassroomRosterController::class, 'options']);
        Route::get('/reports/classroom-roster/{section}', [ClassroomRosterController::class, 'index']);

        // صفحات التفصيل: قسم واحد أو تلميذ واحد
        Route::get('/reports/revenue/classrooms/{section}', [FinancialReportController::class, 'classroomDetail']);
        Route::get('/reports/revenue/students/{student}', [FinancialReportController::class, 'studentDetail']);

        // تقارير الأرصدة الافتتاحية: كشف الديون اليدوية، الملخّص الموحّد
        // (آلي + يدوي).
        Route::get('/reports/manual-debts', [FinancialReportController::class, 'manualDebts']);
        Route::get('/reports/opening-balances-summary', [FinancialReportController::class, 'openingBalancesSummary']);
    });

    // User Management
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        // تغيير كلمة مرور مستخدم من قِبل المشرف — مسار صريح قبل apiResource.
        Route::post('/users/{user}/change-password', [UserController::class, 'changePassword']);
        Route::apiResource('/users', UserController::class);
        Route::apiResource('/fee-types', FeeTypeController::class)->except(['index']);

        // سجل العمليات — قراءة فقط بصلاحية manage_users حصراً.
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    // Direct Permission Overrides (منح / منع الصلاحيات المباشرة للمستخدم)
    Route::middleware('permission:manage_user_permissions')->group(function () {
        Route::get('/users/{user}/permission-overrides', [UserPermissionOverrideController::class, 'index']);
        Route::post('/users/{user}/permission-overrides', [UserPermissionOverrideController::class, 'store']);
        Route::put('/users/{user}/permission-overrides/{permission}', [UserPermissionOverrideController::class, 'update']);
        Route::delete('/users/{user}/permission-overrides/{permission}', [UserPermissionOverrideController::class, 'destroy']);
        Route::get('/users/{user}/effective-permissions', [UserPermissionOverrideController::class, 'effectivePermissions']);
    });

    // School structure — المستويات والأقسام
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/levels', [LevelController::class, 'index']);
        Route::post('/levels', [LevelController::class, 'store']);
        Route::put('/levels/{level}', [LevelController::class, 'update']);
        Route::delete('/levels/{level}', [LevelController::class, 'destroy']);

        Route::get('/sections', [SectionController::class, 'index']);
        Route::post('/sections', [SectionController::class, 'store']);
        Route::put('/sections/{section}', [SectionController::class, 'update']);
        Route::delete('/sections/{section}', [SectionController::class, 'destroy']);
    });

    // قوائم الأقسام — إدخال دفعي، تعديل، حذف، طباعة
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/rosters', [RosterController::class, 'index']);
        Route::post('/rosters/bulk', [RosterController::class, 'bulkStore']);
        Route::put('/rosters/{roster}', [RosterController::class, 'updateStudent']);
        Route::delete('/rosters/{roster}', [RosterController::class, 'destroy']);
    });

    // Students
    Route::middleware('permission:manage_students')->group(function () {
        // Compatibility route for New Student wizard (frontend posts here)
        Route::post('/students/enroll', [StudentController::class, 'store']);
        Route::post('/students/bulk-reenroll', [StudentController::class, 'bulkReenroll']);
        Route::get('/students/search-options', [StudentController::class, 'searchOptions']);
        Route::get('/students/transfer-roster', [StudentController::class, 'transferRoster']);
        Route::post('/students/transfer', [StudentController::class, 'transfer']);
        Route::get('/students/{student}/photo', [StudentController::class, 'photo']);

        Route::apiResource('/students', StudentController::class);
        Route::post('/students/{student}/enroll', [StudentController::class, 'enroll']);
        Route::post('/students/{student}/reenroll', [StudentController::class, 'reenroll']);

        // قبض معلوم الترسيم لتلميذ ترسيمه قائم في السنة النشطة (ترحيل الترقية).
        // مسار مستقل عن reenroll عمداً: هذا يقبض مالاً ولا يُنشئ ترسيماً،
        // فلا يُضعف حارس ازدواج الترسيم ولا يخلط مسؤوليتين في مسار واحد.
        Route::post('/students/{student}/registration-payment', [StudentController::class, 'registrationPayment']);

        Route::get('/students/{student}/balance', [PaymentController::class, 'studentBalance']);
        Route::get('/students/{student}/fees', [PaymentController::class, 'studentFees']);
        Route::get('/students/{student}/payments', [StudentController::class, 'paymentHistory']);
    });

    // Payments
    Route::middleware('permission:manage_payments')->group(function () {
        // «ما تم استخلاصه» — ذاتيّ النطاق؛ يسبق apiResource كي لا يلتقطه GET /payments/{payment}.
        Route::get('/payments/my-collections', [PaymentController::class, 'myCollections']);
        Route::apiResource('/payments', PaymentController::class)->except(['update', 'destroy']);
        Route::post('/payments/{payment}/reprint', [PaymentController::class, 'reprint']);
        Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancel']);
        Route::get('/fee-types', [FeeTypeController::class, 'index']);

        Route::get('/club-sections', [ClubController::class, 'clubSections']);

        Route::get('/collection/years', [CollectionController::class, 'years']);
        Route::get('/collection/years/{year}/sections', [CollectionController::class, 'sectionsByYear']);
        Route::get('/collection/sections/{section}/students', [CollectionController::class, 'studentsBySection']);
        Route::get('/payments/collect/preview', [CollectionController::class, 'preview']);
        Route::post('/payments/collect', [CollectionController::class, 'collect']);

        // رصيد افتتاحي ومعاينة توزيع الدفعة — يراهما القابض قبل تثبيت الوصل.
        Route::get('/collection/students/{student}/opening-balances', [CollectionController::class, 'openingBalances']);
        Route::get('/collection/students/{student}/allocation-preview', [CollectionController::class, 'allocationPreview']);

        // موديول العائلات والتحصيل الجماعي
        Route::get('/families', [FamilyController::class, 'index']);
        Route::get('/families/{family}', [FamilyController::class, 'show']);
        Route::post('/families/{family}/collect', [FamilyController::class, 'collect']);
        // تنبيه الديون القديمة لأبناء العائلة — قراءة فقط في manual_student_debts
        // لبانر غير حاجب؛ لا ينشئ دفعة ولا قيدا ولا يعدّل أي رصيد.
        Route::get('/families/{family}/old-debts', [FamilyController::class, 'oldDebts']);

        Route::get('/enrollments/{enrollment}/ledger', [CollectionController::class, 'ledger']);

        // استخلاص الديون القديمة — تحصيل عبر CollectionService نفسه (prior_year_debt/in)
        // وقراءات السجلّ والكشف. الإلغاء عبر مسار الوصلات العام أعلاه.
        Route::get('/students/{student}/old-debt-summary', [ManualDebtController::class, 'summary']);
        Route::post('/manual-debts/{debt}/collect', [ManualDebtController::class, 'collect']);
        Route::get('/manual-debts/{debt}/payments', [ManualDebtController::class, 'payments']);
        Route::get('/manual-debts/{debt}/statement', [ManualDebtController::class, 'statement']);
    });

    // التنازل عن الدُّيون — صلاحية مستقلة عن manage_payments عمداً:
    // القابض يقبض المال ولا يُسقِط دَيناً؛ الإسقاط لصاحب النظام وحده.
    // لا يُكتب أي سطر في الخزينة: التنازل لا يحرّك مليماً.
    Route::middleware('permission:waive_fees')->group(function () {
        Route::get('/student-fees/{studentFee}/waivers', [FeeWaiverController::class, 'index']);
        Route::post('/student-fees/{studentFee}/waive', [FeeWaiverController::class, 'store']);
        Route::post('/fee-waivers/{waiver}/cancel', [FeeWaiverController::class, 'cancel']);

        // التخفيض السنوي — سعر مخفّض لا دَين. يتبع صلاحية التنازل نفسها:
        // كلاهما يُنقص ما تجنيه المدرسة فلا يملكه القابض. لا سطر في الخزينة.
        Route::get('/enrollments/{enrollment}/discount', [DiscountController::class, 'show']);
        Route::post('/enrollments/{enrollment}/discount', [DiscountController::class, 'store']);
        Route::post('/discounts/{discount}/cancel', [DiscountController::class, 'cancel']);

        // التخفيضات الشهرية المتكررة (دراسي / نوادي)
        Route::get('/enrollments/{enrollment}/monthly-discounts', [MonthlyDiscountController::class, 'index']);
        Route::post('/enrollments/{enrollment}/monthly-discounts', [MonthlyDiscountController::class, 'store']);
        Route::post('/monthly-discounts/{discount}/cancel', [MonthlyDiscountController::class, 'cancel']);

        Route::get('/club-subscriptions/{subscription}/monthly-discounts', [ClubMonthlyDiscountController::class, 'index']);
        Route::post('/club-subscriptions/{subscription}/monthly-discounts', [ClubMonthlyDiscountController::class, 'store']);
        Route::post('/club-monthly-discounts/{discount}/cancel', [ClubMonthlyDiscountController::class, 'cancel']);

        // إدارة الإعفاءات للتلاميذ (دراسي / نوادي)
        Route::get('/exemptions', [ExemptionController::class, 'allExemptions']);
        Route::get('/enrollments/{enrollment}/exemptions', [ExemptionController::class, 'index']);
        Route::post('/enrollments/{enrollment}/exemptions/monthly', [ExemptionController::class, 'storeMonthly']);
        Route::post('/enrollments/{enrollment}/exemptions/club/{clubSubscription}', [ExemptionController::class, 'storeClub']);
        Route::delete('/exemptions/monthly/{monthlyDiscount}', [ExemptionController::class, 'cancelMonthly']);
        Route::delete('/exemptions/club/{clubMonthlyDiscount}', [ExemptionController::class, 'cancelClub']);
    });

    // النوادي المدرسية واشتراكاتها
    Route::middleware('permission:manage_students')->group(function () {
        Route::apiResource('/clubs', ClubController::class);
        Route::apiResource('/club-subscriptions', ClubSubscriptionController::class)->except(['update']);
        Route::post('/club-subscriptions/{subscription}/exclude', [ClubSubscriptionController::class, 'exclude']);
        Route::post('/club-subscriptions/{subscription}/restore', [ClubSubscriptionController::class, 'restore']);
    });

    // تقارير واستخلاص معلوم النوادي
    Route::middleware('permission:manage_payments')->group(function () {
        Route::get('/reports/club-fees', [ClubReportController::class, 'report']);
        Route::get('/reports/club-arrears', [ClubReportController::class, 'arrearsDashboard']);
        Route::post('/reports/club-fees/generate', [ClubReportController::class, 'generateMonth']);
        Route::post('/club-monthly-fees/{monthlyFee}/collect', [ClubReportController::class, 'collectPayment']);
        Route::post('/club-monthly-fees/{monthlyFee}/cancel', [ClubReportController::class, 'cancelPayment']);
        Route::delete('/club-monthly-fees/{monthlyFee}', [ClubReportController::class, 'destroy']);
    });
});
