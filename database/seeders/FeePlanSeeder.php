<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Monthly fee plans for the active academic year.
 *
 * One plan per level (nine levels) and never one per month:
 * FeeService::generateMonthlyFees walks the academic year month by month
 * starting from a single monthly plan, so a plan per month would multiply
 * the generated instalments by ten.
 *
 * Idempotent: keyed on (academic_year_id, level_id, frequency).
 * Creates no student_fees, no payments and no cash_transactions.
 *
 * Run: php artisan db:seed --class=FeePlanSeeder
 */
class FeePlanSeeder extends Seeder
{
    /** Reference monthly fee per level code, in dinars. */
    private const PLANS = [
        'L1'   => ['amount' => 150.00, 'name' => 'القسط الشهري — الأولى'],
        'L2'   => ['amount' => 150.00, 'name' => 'القسط الشهري — الثانية'],
        'L3'   => ['amount' => 160.00, 'name' => 'القسط الشهري — الثالثة'],
        'L4'   => ['amount' => 160.00, 'name' => 'القسط الشهري — الرابعة'],
        'L5'   => ['amount' => 180.00, 'name' => 'القسط الشهري — الخامسة'],
        'L6'   => ['amount' => 180.00, 'name' => 'القسط الشهري — السادسة'],
        'PRE1' => ['amount' => 90.00,  'name' => 'القسط الشهري — الروضة'],
        'PRE2' => ['amount' => 100.00, 'name' => 'القسط الشهري — التمهيدي'],
        'PRE3' => ['amount' => 120.00, 'name' => 'القسط الشهري — التحضيري'],
    ];

    public function run(): void
    {
        $year = DB::table('academic_years')->where('is_active', 1)->first();

        if (! $year) {
            $this->command?->error('لا توجد سنة دراسية نشطة. فعّل السنة أولاً ثم أعد التشغيل.');

            return;
        }

        $category = DB::table('fee_categories')->where('code', 'scolarite')->first()
            ?: DB::table('fee_categories')->orderBy('id')->first();

        if (! $category) {
            $this->command?->error('لا يوجد صنف رسوم واحد على الأقل في fee_categories.');

            return;
        }

        $now = now();
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach (DB::table('levels')->orderBy('id')->get() as $level) {
            $code = $level->code ?? null;

            if ($code === null || ! isset(self::PLANS[$code])) {
                $skipped[] = $code ?? ('#' . $level->id);

                continue;
            }

            $plan = self::PLANS[$code];

            $keys = [
                'academic_year_id' => $year->id,
                'level_id'         => $level->id,
                'frequency'        => 'monthly',
            ];

            $values = [
                'fee_category_id' => $category->id,
                'name'            => $plan['name'],
                'amount'          => $plan['amount'],
                'due_day'         => 1,
                'updated_at'      => $now,
            ];

            $existing = DB::table('fee_plans')->where($keys)->first();

            if ($existing) {
                DB::table('fee_plans')->where('id', $existing->id)->update($values);
                $updated++;

                continue;
            }

            DB::table('fee_plans')->insert($keys + $values + ['created_at' => $now]);
            $created++;
        }

        $yearLabel = $year->name ?? ('#' . $year->id);
        $categoryLabel = $category->name ?? ('#' . $category->id);

        $this->command?->info("السنة الدراسية: {$yearLabel} | صنف الرسوم: {$categoryLabel}");
        $this->command?->info("مخططات جديدة: {$created} | مخططات محدَّثة: {$updated}");

        if ($skipped !== []) {
            $this->command?->warn('مستويات بلا مبلغ مرجعي (تم تجاوزها): ' . implode(', ', $skipped));
        }
    }
}
