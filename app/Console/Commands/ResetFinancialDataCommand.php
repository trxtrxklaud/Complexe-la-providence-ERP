<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetFinancialDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:reset-financial {--force : تجاوز طلب التأكيد المكتوب}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'تصفير كافة البيانات المالية والتجريبية مع الحفاظ الصارم على بيانات التلاميذ وهيكل المدرسة';

    /**
     * الجداول المالية والتجريبية المستهدفة بالتصفير (مرتبة من الأبناء إلى الآباء).
     *
     * @var array<int, string>
     */
    protected array $targetTables = [
        'cash_transactions',
        'payment_allocations',
        'fee_waivers',
        'student_fees',
        'club_monthly_fees',
        'club_monthly_discounts',
        'monthly_discounts',
        'enrollment_discounts',
        'manual_student_debts',
        'employee_advance_repayments',
        'employee_advances',
        'employee_daily_hours',
        'employee_liabilities',
        'salaries',
        'expenses',
        'treasury_withdrawals',
        'opening_balances',
        'payments',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->warn(' تحذير أمني: تصفير البيانات المالية والتجريبية للنظام');
        $this->warn('═══════════════════════════════════════════════════════════════');
        $this->line('هذا الأمر سيقوم بحذف كافة السجلات المالية (المقبوضات، المصاريف، الخزينة، الرواتب، الديون، المعاليم)');
        $this->info('البيانات المحمية التي لن تُمس نهائياً:');
        $this->line(' • التلاميذ (students) والتسجيلات (enrollments) والأولياء (guardians)');
        $this->line(' • بنية المدرسة: السنوات (academic_years)، المستويات (levels)، الأقسام (sections)');
        $this->line(' • المستخدمون (users)، الأدوار (roles)، الصلاحيات (permissions)');
        $this->line(' • إعدادات المعاليم (fee_types, fee_plans) والنوادي (clubs) والإطارات (employees)');
        $this->newLine();

        if (! $this->option('force')) {
            $confirm = $this->ask('هل أنت متأكد؟ اكتب YES للمتابعة');

            if ($confirm !== 'YES') {
                $this->error('تم إلغاء العملية: لم يتم تأكيد الإدخال بـ YES.');

                return self::SUCCESS;
            }
        }

        $driver = DB::getDriverName();
        $this->info("بدء التصفير داخل معاملة آمنة (Database Driver: {$driver})...");

        $report = [];
        $totalDeleted = 0;

        try {
            $this->disableForeignKeys($driver);

            DB::transaction(function () use (&$report, &$totalDeleted) {
                foreach ($this->targetTables as $table) {
                    if (! Schema::hasTable($table)) {
                        $report[] = [
                            'table' => $table,
                            'status' => 'غير موجود',
                            'deleted' => 0,
                        ];

                        continue;
                    }

                    $count = DB::table($table)->count();
                    DB::table($table)->delete();

                    $report[] = [
                        'table' => $table,
                        'status' => 'تم التصفير',
                        'deleted' => $count,
                    ];
                    $totalDeleted += $count;
                }
            });
        } finally {
            $this->enableForeignKeys($driver);
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' تقرير تصفير البيانات المالية');
        $this->info('═══════════════════════════════════════════════════════════════');

        $headers = ['الجدول', 'الحالة', 'عدد السجلات المحذوفة'];
        $rows = array_map(fn ($r) => [$r['table'], $r['status'], number_format($r['deleted'])], $report);
        $this->table($headers, $rows);

        $this->newLine();
        $this->info('اكتملت العملية بنجاح! إجمالي السجلات المحذوفة: '.number_format($totalDeleted));

        return self::SUCCESS;
    }

    /**
     * تعطيل فحص المفاتيح الأجنبية حسب نوع قاعدة البيانات.
     */
    protected function disableForeignKeys(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } elseif ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }
    }

    /**
     * إعادة تفعيل فحص المفاتيح الأجنبية حسب نوع قاعدة البيانات.
     */
    protected function enableForeignKeys(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } elseif ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }
}
