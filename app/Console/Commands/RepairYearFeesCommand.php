<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\Level;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairYearFeesCommand extends Command
{
    protected $signature = 'legacy:repair-year-fees
                            {--sqlite-path= : Path to legacy sqlite database file}
                            {--dry-run : Test import and validation without committing changes}';

    protected $description = 'Import 2026-2027 Fee Plans from legacy SQLite and set 2026-2027 as the active academic year';

    public function handle(): int
    {
        $sqlitePath = $this->option('sqlite-path') ?: env('DB_SQLITE_LEGACY_PATH', database_path('legacy.sqlite'));

        if (! file_exists($sqlitePath)) {
            $sqlitePath = database_path('database.sqlite');
        }

        if (! file_exists($sqlitePath)) {
            $this->error("❌ ملف قاعدة بيانات SQLite القديمة غير موجود في المسار: {$sqlitePath}");
            return self::FAILURE;
        }

        $this->info("🔌 الاتصال بقاعدة SQLite القديمة: {$sqlitePath}");

        config(['database.connections.sqlite_legacy' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $legacyDb = DB::connection('sqlite_legacy');

        try {
            $legacyDb->getPdo();
        } catch (\Throwable $e) {
            $this->error("فشل الاتصال بقاعدة بيانات SQLite: " . $e->getMessage());
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn("⚠️  وضع التجربة (DRY-RUN) مفعّل: لن يتم حفظ أي تعديلات في قاعدة البيانات.");
        }

        // 1. عرض الإحصائيات الحالية قبل التنفيذ
        $this->info("\n--- الإحصائيات الحالية في قاعدة بيانات الوجهة (MySQL) ---");
        $currentActiveYear = AcademicYear::where('is_active', true)->first();
        $this->line("السنة النشطة حالياً: " . ($currentActiveYear ? "{$currentActiveYear->name} (ID: {$currentActiveYear->id})" : 'لا توجد سنة نشطة'));

        $enrollmentsSummary = DB::table('enrollments')
            ->join('academic_years', 'enrollments.academic_year_id', '=', 'academic_years.id')
            ->select('academic_years.name as year_name', DB::raw('count(*) as count'))
            ->groupBy('academic_years.name')
            ->get();

        foreach ($enrollmentsSummary as $es) {
            $this->line("التسجيلات في السنة {$es->year_name}: {$es->count}");
        }

        $targetPlansCount = FeePlan::count();
        $this->line("إجمالي خطط الرسوم (Fee Plans) في الوجهة: {$targetPlansCount}");

        // 2. البحث عن سنة 2026-2027 في القاعدتين
        $targetYearName = '2026-2027';
        $targetYear = AcademicYear::where('name', $targetYearName)->first();

        if (! $targetYear) {
            $this->error("❌ السنة الدراسية '{$targetYearName}' غير موجودة في قاعدة بيانات الوجهة (MySQL).");
            return self::FAILURE;
        }

        $legacyYear = $legacyDb->table('academic_years')->where('name', $targetYearName)->first();
        if (! $legacyYear) {
            $this->error("❌ السنة الدراسية '{$targetYearName}' غير موجودة في قاعدة بيانات المصدر (SQLite).");
            return self::FAILURE;
        }

        // 3. قراءة خطط الرسوم من SQLite لسنة 2026-2027
        $legacyPlans = $legacyDb->table('fee_plans')
            ->where('academic_year_id', $legacyYear->id)
            ->get();

        if ($legacyPlans->isEmpty()) {
            $this->error("❌ لا توجد أي خطط رسوم (Fee Plans) للسنة '{$targetYearName}' في قاعدة بيانات SQLite.");
            return self::FAILURE;
        }

        $this->info("\nعدد خطط الرسوم المقروءة من SQLite لسنة {$targetYearName}: {$legacyPlans->count()}");

        // 4. بناء خرائط المطابقة للـ Levels و Categories
        $legacyLevels = $legacyDb->table('levels')->get()->keyBy('id');
        $legacyCategories = $legacyDb->table('fee_categories')->get()->keyBy('id');

        $targetLevelsByCode = Level::all()->keyBy('code');
        $targetCategoriesByCode = FeeCategory::all()->keyBy('code');

        $plansToProcess = [];
        $unmatchedErrors = [];

        foreach ($legacyPlans as $lp) {
            $oldLevel = $legacyLevels->get($lp->level_id);
            if (! $oldLevel) {
                $unmatchedErrors[] = "خطأ: المستوى ID {$lp->level_id} غير موجود في مستويات SQLite.";
                continue;
            }

            $targetLevel = $targetLevelsByCode->get($oldLevel->code)
                ?? Level::where('name', $oldLevel->name)->first();

            if (! $targetLevel) {
                $unmatchedErrors[] = "خطأ: تعذر مطابقة المستوى '{$oldLevel->name}' (Code: {$oldLevel->code}) في MySQL.";
                continue;
            }

            $oldCat = $legacyCategories->get($lp->fee_category_id);
            $targetCat = null;
            if ($oldCat) {
                $targetCat = $targetCategoriesByCode->get($oldCat->code)
                    ?? FeeCategory::where('name', $oldCat->name)->first();
            }

            if (! $targetCat) {
                $targetCat = FeeCategory::where('code', 'scolarite')->first()
                    ?? FeeCategory::first();
            }

            if (! $targetCat) {
                $unmatchedErrors[] = "خطأ: تعذر مطابقة فئة الرسوم في MySQL.";
                continue;
            }

            $plansToProcess[] = [
                'academic_year_id' => $targetYear->id,
                'level_id' => $targetLevel->id,
                'level_code' => $targetLevel->code,
                'level_name' => $targetLevel->name,
                'fee_category_id' => $targetCat->id,
                'name' => $lp->name ?: ("القسط الشهري — " . $targetLevel->name),
                'amount' => (float) $lp->amount,
                'frequency' => $lp->frequency ?: 'monthly',
                'due_day' => (int) ($lp->due_day ?: 1),
            ];
        }

        if (! empty($unmatchedErrors)) {
            foreach ($unmatchedErrors as $err) {
                $this->error($err);
            }
            $this->error("❌ توقف الأمر لوجود أخطاء في مطابقة المستويات أو فئات الرسوم.");
            return self::FAILURE;
        }

        // 5. التنفيذ داخل Transaction
        DB::beginTransaction();

        try {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $tableDetails = [];

            foreach ($plansToProcess as $planData) {
                $existing = FeePlan::where('academic_year_id', $planData['academic_year_id'])
                    ->where('level_id', $planData['level_id'])
                    ->where('frequency', $planData['frequency'])
                    ->where('fee_category_id', $planData['fee_category_id'])
                    ->first();

                if ($existing) {
                    if ((float) $existing->amount !== (float) $planData['amount'] || $existing->name !== $planData['name']) {
                        if (! $isDryRun) {
                            $existing->update([
                                'name' => $planData['name'],
                                'amount' => $planData['amount'],
                                'due_day' => $planData['due_day'],
                            ]);
                        }
                        $updated++;
                        $tableDetails[] = [$planData['level_code'], $planData['level_name'], $planData['amount'] . ' د.ت', 'تحديث القيمة'];
                    } else {
                        $skipped++;
                        $tableDetails[] = [$planData['level_code'], $planData['level_name'], $planData['amount'] . ' د.ت', 'مطابق مسبقاً (تجاوز)'];
                    }
                } else {
                    if (! $isDryRun) {
                        FeePlan::create([
                            'academic_year_id' => $planData['academic_year_id'],
                            'level_id' => $planData['level_id'],
                            'fee_category_id' => $planData['fee_category_id'],
                            'name' => $planData['name'],
                            'amount' => $planData['amount'],
                            'frequency' => $planData['frequency'],
                            'due_day' => $planData['due_day'],
                        ]);
                    }
                    $created++;
                    $tableDetails[] = [$planData['level_code'], $planData['level_name'], $planData['amount'] . ' د.ت', 'إنشاء جديد'];
                }
            }

            // تفعيل سنة 2026-2027 كالسنة الوحيدة النشطة
            if (! $isDryRun) {
                AcademicYear::where('id', '!=', $targetYear->id)->update(['is_active' => false]);
                $targetYear->update(['is_active' => true]);
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->warn("\n🏁 اكتمال فحص التجربة (DRY-RUN): تم التراجع عن كافة التغييرات.");
            } else {
                DB::commit();
                $this->info("\n✅ تم تثبيت خطط الرسوم وتفعيل السنة الدراسية بنجاح داخل قاعدة البيانات.");
            }

            $this->table(
                ['كود المستوى', 'اسم المستوى', 'المبلغ الشهري', 'الحالة'],
                $tableDetails
            );

            $finalActiveYear = $isDryRun ? $currentActiveYear?->name : $targetYearName;
            $this->info("\n📊 ملخص العملية:");
            $this->line("- خطط رسوم جديدة (Created): {$created}");
            $this->line("- خطط رسوم محدثة (Updated): {$updated}");
            $this->line("- خطط رسوم مطابقة مسبقاً (Skipped): {$skipped}");
            $this->line("- السنة النشطة النهائية في المنظومة: " . ($isDryRun ? "{$finalActiveYear} (لم تُغيَّر - وضع تجربة)" : "{$finalActiveYear} ✅"));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("❌ فشلت العملية وتم التراجع عن كافة التغييرات: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
