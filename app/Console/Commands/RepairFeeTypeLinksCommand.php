<?php

namespace App\Console\Commands;

use App\Models\FeeType;
use App\Models\Payment;
use App\Models\StudentFee;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ترميم روابط أنواع المعاليم للرسوم القديمة.
 *
 * الرسوم المستخلَصة قبل إضافة عمود fee_type_id لا تحمل أي رابط بنيوي لنوع المعلوم،
 * فيُصنّفها الدفتر كلّها في «معاليم الأشهر» وهو تصنيف غير دقيق.
 *
 * لحسن الحظ الوصف مخزّن بصيغة ثابتة: «اسم النوع — الأشهر»، فيُستخرج منه النوع
 * مرة واحدة ويُثبّت في العمود، ثم تُعاد الدفعات المعنيّة إلى الدفتر لتُصنّف من جديد.
 * بعد هذا الأمر لا يعتمد أي منطق مالي على مطابقة النصوص مطلقاً.
 */
class RepairFeeTypeLinksCommand extends Command
{
    protected $signature = 'ledger:repair-fee-types {--dry-run : عرض النتائج دون كتابة}';

    protected $description = 'ربط الرسوم القديمة بأنواع المعاليم انطلاقاً من الوصف، ثم إعادة تصنيفها في الدفتر';

    public function handle(LedgerService $ledger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // خريطة الأسماء المُطبّعة → معرّف النوع، حتى لا تُفشل المطابقة بسبب تشكيل أو ألف مهموزة.
        $byName = [];
        foreach (FeeType::all(['id', 'name_ar']) as $type) {
            $byName[FeeType::normalize((string) $type->name_ar)] = $type->id;
        }

        if ($byName === []) {
            $this->warn('لا توجد أنواع معاليم مسجّلة. شغّل FeeTypeSeeder أولاً.');

            return self::FAILURE;
        }

        $feeIds = StudentFee::whereNull('fee_type_id')
            ->whereNull('fee_plan_id')
            ->pluck('id');

        if ($feeIds->isEmpty()) {
            $this->info('كل الرسوم مربوطة بأنواعها أو بخططها. لا حاجة للترميم.');

            return self::SUCCESS;
        }

        $this->line(sprintf('رسوم بلا نوع: %d', $feeIds->count()));

        $matched = 0;
        $unmatched = [];
        $paymentIds = [];

        // يُقرأ بمعرّفات مجموعة مسبقاً لا بـ chunkById على شرط whereNull، لأن التحديث
        // ينقل الصفوف خارج الشرط فتُتخطّى صفوف أخرى أثناء الترقيم.
        foreach ($feeIds->chunk(200) as $chunk) {
            $fees = StudentFee::with('paymentAllocations:id,payment_id,student_fee_id')
                ->whereIn('id', $chunk)
                ->get();

            foreach ($fees as $fee) {
                $prefix = FeeType::normalize(
                    trim(explode('—', (string) $fee->description, 2)[0])
                );

                $typeId = $byName[$prefix] ?? null;

                if ($typeId === null) {
                    $unmatched[$prefix] = ($unmatched[$prefix] ?? 0) + 1;
                    continue;
                }

                if (! $dryRun) {
                    // تحديث مباشر بلا أحداث أو تعديل لـ updated_at: ترميم بيانات لا تعديل مستند.
                    DB::table('student_fees')
                        ->where('id', $fee->id)
                        ->update(['fee_type_id' => $typeId]);
                }

                $matched++;

                foreach ($fee->paymentAllocations as $allocation) {
                    $paymentIds[$allocation->payment_id] = true;
                }
            }
        }

        $this->info(sprintf('تمت مطابقة %d رسم', $matched));

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('أوصاف لم تُطابق أي نوع معلوم (تبقى في بند معاليم الأشهر):');
            foreach ($unmatched as $name => $count) {
                $this->line(sprintf('  - «%s» × %d', $name, $count));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('عرض فقط — لم تُكتب أي تغييرات.');

            return self::SUCCESS;
        }

        $ids = array_keys($paymentIds);

        if ($ids === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('إعادة تصنيف %d دفعة في الدفتر...', count($ids)));

        Payment::with([
            'paymentAllocations.studentFee.feePlan',
            'paymentAllocations.studentFee.feeType',
            'enrollment',
        ])
            ->whereIn('id', $ids)
            ->chunkById(100, function ($payments) use ($ledger) {
                foreach ($payments as $payment) {
                    $ledger->recordPayment($payment);
                }
            });

        $this->info('انتهى الترميم. بنود المداخيل أصبحت مطابقة لأنواع المعاليم الفعلية.');

        return self::SUCCESS;
    }
}
