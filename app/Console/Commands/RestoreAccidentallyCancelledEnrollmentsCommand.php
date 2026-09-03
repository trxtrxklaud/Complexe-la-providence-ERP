<?php

namespace App\Console\Commands;

use App\Models\Enrollment;
use Illuminate\Console\Command;

class RestoreAccidentallyCancelledEnrollmentsCommand extends Command
{
    protected $signature = 'enrollments:restore-cancelled';
    protected $description = 'استرجاع الترسيمات التي تم حذفها بالخطأ أثناء تجربة الإلغاء مع إبقائها في أقسامها';

    public function handle(): int
    {
        $trashed = Enrollment::onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays(2))
            ->get();

        if ($trashed->isEmpty()) {
            $this->info('لا توجد ترسيمات محذوفة حديثاً.');
            return self::SUCCESS;
        }

        $this->info("تم العثور على {$trashed->count()} ترسيم محذوف مؤخراً:");

        foreach ($trashed as $enrollment) {
            $enrollment->restore();
            $studentName = trim(($enrollment->student?->first_name ?? '') . ' ' . ($enrollment->student?->last_name ?? ''));
            $sectionName = $enrollment->section?->name ?? 'بدون قسم';
            $this->line(" ✓ تم استرجاع التلميذ: {$studentName} (رقم {$enrollment->student_id}) إلى قسم: {$sectionName}");
        }

        // تنظيف أي مخصصات لدفعات ملغاة لتعود الرسوم غير مدفوعة
        $cancelledPaymentIds = \App\Models\Payment::whereNotNull('cancelled_at')->pluck('id');
        if ($cancelledPaymentIds->isNotEmpty()) {
            \App\Models\PaymentAllocation::whereIn('payment_id', $cancelledPaymentIds)->delete();
        }

        // حذف الرسوم المؤقتة التي ليس لها خطة ولا مخصصات نشطة
        \App\Models\StudentFee::whereNull('fee_plan_id')
            ->whereDoesntHave('paymentAllocations')
            ->delete();

        // تحديث حالة باقي الرسوم إلى pending
        $paymentService = app(\App\Services\PaymentService::class);
        foreach (\App\Models\StudentFee::all() as $fee) {
            $paymentService->recalculateStudentFeeStatus($fee->id);
        }

        $this->info('تمت استعادة كافة التلاميذ إلى أقسامهم وتصفير معاليمهم الملغاة بنجاح.');
        return self::SUCCESS;
    }
}
