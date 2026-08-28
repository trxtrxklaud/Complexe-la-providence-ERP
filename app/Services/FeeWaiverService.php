<?php

namespace App\Services;

use App\Models\FeeWaiver;
use App\Models\StudentFee;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * التنازل عن متبقّي رسم.
 *
 * لا يُكتب أي سطر في الدفتر النقدي: التنازل لم يُدخِل مليماً ولم يُخرِجه،
 * فلا أثر له في الخزينة ولا في الدخل الصافي. أثره الوحيد إقفال دَين.
 */
class FeeWaiverService
{
    public function waive(StudentFee $fee, float $amount, string $reason, ?int $userId = null): FeeWaiver
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('سبب التنازل إجباري');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ التنازل يجب أن يكون أكبر من صفر');
        }

        return DB::transaction(function () use ($fee, $amount, $reason, $userId) {
            // قفل صف الرسم: تنازلان متزامنان لا يجوز أن يتجاوزا المتبقّي معاً.
            $locked = StudentFee::whereKey($fee->id)->lockForUpdate()->firstOrFail();

            $outstanding = $locked->outstanding();

            if ($outstanding <= 0) {
                throw new \InvalidArgumentException('لا يوجد مبلغ متبقٍّ على هذا الرسم');
            }

            if ($amount > $outstanding) {
                throw new \InvalidArgumentException(
                    'مبلغ التنازل (' . $amount . ') يتجاوز المتبقّي (' . $outstanding . ')'
                );
            }

            $waiver = FeeWaiver::create([
                'student_fee_id' => $locked->id,
                'amount'         => $amount,
                'reason'         => $reason,
                'created_by'     => $userId,
            ]);

            AuditService::log(
                'fee_waiver.create',
                'تنازل عن رسم #'.$waiver->student_fee_id.' بمبلغ '.$waiver->amount.' د.ت — السبب: '.$reason,
                $waiver,
                [
                    'student_fee_id' => $locked->id,
                    'amount'         => (float) $waiver->amount,
                    'reason'         => $reason,
                    'created_by'     => $userId,
                ]
            );

            $this->syncStatus($locked);

            return $waiver;
        });
    }

    /**
     * إلغاء تنازل: يعود الدَّين كما كان، ويبقى أثر التنازل وإلغائه مقروءاً.
     */
    public function cancel(FeeWaiver $waiver, string $reason, ?int $userId = null): FeeWaiver
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('سبب الإلغاء إجباري');
        }

        if ($waiver->isCancelled()) {
            throw new \InvalidArgumentException('هذا التنازل ملغى مسبقاً');
        }

        return DB::transaction(function () use ($waiver, $reason, $userId) {
            $waiver->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $userId,
                'cancellation_reason' => $reason,
            ]);

            AuditService::log(
                'fee_waiver.cancel',
                'إلغاء تنازل #'.$waiver->id.' — السبب: '.$reason,
                $waiver,
                [
                    'waiver_id'    => $waiver->id,
                    'reason'       => $reason,
                    'cancelled_by' => $userId,
                ]
            );

            $fee = StudentFee::find($waiver->student_fee_id);

            if ($fee) {
                $this->syncStatus($fee);
            }

            return $waiver->refresh();
        });
    }

    /**
     * حالة الرسم تُشتقّ ولا تُكتب يدوياً.
     *
     * المقفل بتنازل يُخزّن paid لأنّ قائمة الحالات لا تعرف «waived»؛ وسجلّ التنازل
     * هو من يوثّق أنّ الإقفال لم يكن نقداً. التقارير تقرأ التوزيعات والدفتر،
     * لا عمود الحالة، فلا يتولّد مدخول وهمي.
     */
    private function syncStatus(StudentFee $fee): void
    {
        $allocated = $fee->allocatedAmount();
        $waived    = $fee->waivedAmount();

        $status = match (true) {
            $allocated + $waived >= (float) $fee->amount_due => 'paid',
            $allocated > 0                                   => 'partial',
            default                                          => 'pending',
        };

        if ($fee->status !== $status) {
            $fee->update(['status' => $status]);
        }
    }
}
