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

        $this->info('تمت استعادة كافة التلاميذ إلى أقسامهم بنجاح.');
        return self::SUCCESS;
    }
}
