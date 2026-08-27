<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\OpeningBalance;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل الأرصدة الافتتاحية عند إقفال السنة الدراسية.
 *
 * التنظيف هنا قاطع: عند إقفال 2025–2026 تُحتسب كل الرسوم غير المدفوعة
 * للمسجلين في السنة، وتُنقل كل متخلَّدة على حدة إلى السنة الجديدة كرصيد
 * افتتاحي يحفظ مصدر الدَّين (الرسم الأصلي) وسنته الأصلية. لا يُحذف دَين
 * قديم أبداً، ولا يُرتّب له سطر في الدفتر النقدي — التحصيل حين يقع يظهر
 * في الخزينة كقبض لدفع قديم لا كمدخولٍ جديد.
 *
 * الإقفال idempotent: قيد الفرادة (source_student_fee_id, academic_year_id)
 * يمنع ترحيل الدَّين نفسه مرتين إلى نفس السنة، وclosed_at على السنة الماضية
 * يمنع تكرار عملية الإقفال كلها.
 */
class OpeningBalanceService
{
    /**
     * إقفال سنة دراسية وترحيل متخلّداتها إلى سنة جديدة.
     *
     * @return array<string,int>
     */
    public function closeYear(AcademicYear $closingYear, AcademicYear $targetYear, ?int $userId = null): array
    {
        if ((int) $closingYear->id === (int) $targetYear->id) {
            throw new \InvalidArgumentException('لا يمكن ترحيل متخلّدات السنة إلى نفسها');
        }

        if ($closingYear->isClosed()) {
            throw new \InvalidArgumentException('السنة الدراسية الماضية مغلقة مسبقاً، والترحيل محمي من الازدواج');
        }

        if ($closingYear->is_active) {
            throw new \InvalidArgumentException('لا يمكن إقفال السنة الدراسية النشطة؛ فعّل السنة الجديدة أولاً');
        }

        return DB::transaction(function () use ($closingYear, $targetYear, $userId) {
            // قفل السنتين يمنع إقفالًا متزامنًا أو إنشاء أرصدة افتتاحية متكررة.
            $closingYear = AcademicYear::query()->whereKey($closingYear->id)->lockForUpdate()->firstOrFail();
            $targetYear = AcademicYear::query()->whereKey($targetYear->id)->lockForUpdate()->firstOrFail();

            // إعادة فحص الحواجز بعد القفل، لأن الحالة قد تكون تغيرت أثناء انتظار القفل.
            if ($closingYear->isClosed()) {
                throw new \InvalidArgumentException('السنة الدراسية الماضية مغلقة مسبقاً، والترحيل محمي من الازدواج');
            }

            if ($closingYear->is_active) {
                throw new \InvalidArgumentException('لا يمكن إقفال السنة الدراسية النشطة؛ فعّل السنة الجديدة أولاً');
            }

            $fees = StudentFee::query()
                ->whereHas('enrollment', fn ($q) => $q
                    ->where('academic_year_id', $closingYear->id)
                    ->where('status', 'active'))
                ->with('enrollment:id,student_id,academic_year_id')
                ->get();

            $created = 0;
            $paidOrWaived = 0;
            $skipped = 0;

            foreach ($fees as $fee) {
                $outstanding = $fee->outstanding();

                if ($outstanding <= 0) {
                    $paidOrWaived++;

                    continue;
                }

                $enrollment = $fee->enrollment;

                if (! $enrollment) {
                    $skipped++;

                    continue;
                }

                // updateOrCreate يجعل الإقفال قابلاً لإعادة التشغيل بلا ترحيل مزدوج.
                OpeningBalance::updateOrCreate(
                    [
                        'source_student_fee_id' => $fee->id,
                        'academic_year_id' => $targetYear->id,
                    ],
                    [
                        'student_id' => $enrollment->student_id,
                        'source_enrollment_id' => $enrollment->id,
                        'amount' => $outstanding,
                        'status' => OpeningBalance::STATUS_PENDING,
                        'created_by' => $userId,
                        // إعادة التشغيل تُعيد تفعيل سطر كان ملغى.
                        'cancelled_at' => null,
                        'cancelled_by' => null,
                        'cancellation_reason' => null,
                    ]
                );

                $created++;
            }

            $closingYear->update(['closed_at' => now()]);

            return [
                'created' => $created,
                'paid_or_waived' => $paidOrWaived,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * متخلّدات السنة الماضية لطالب — بديل العرض الحي عن الرصيد الافتتاحي
     * ليظهر للمحاسب قبل قبض أي مبلغ.
     *
     * @return array<int,array<string,mixed>>
     */
    public function priorYearFeesForStudent(Student $student, ?int $targetYearId = null): array
    {
        $targetYearId ??= AcademicYear::where('is_active', true)->value('id');

        $balances = OpeningBalance::query()
            ->with(['sourceStudentFee.enrollment:id,academic_year_id', 'sourceStudentFee.feeType:id,name_ar'])
            ->where('student_id', $student->id)
            ->active()
            ->when($targetYearId, fn ($q) => $q->where('academic_year_id', $targetYearId))
            ->get();

        return $balances
            ->filter(fn (OpeningBalance $b) => $b->outstanding() > 0)
            ->map(fn (OpeningBalance $b) => $this->formatBalance($b))
            ->values()
            ->all();
    }

    /**
     * كل الأرصدة الافتتاحية السارية لتلميذ (المطلوب في تقارير وفول).
     *
     * @return array<string,mixed>
     */
    public function summaryForStudent(Student $student, ?int $targetYearId = null): array
    {
        $targetYearId ??= AcademicYear::where('is_active', true)->value('id');

        $balances = OpeningBalance::query()
            ->with('sourceStudentFee')
            ->where('student_id', $student->id)
            ->active()
            ->when($targetYearId, fn ($q) => $q->where('academic_year_id', $targetYearId))
            ->get();

        $outstanding = (float) $balances->sum(fn (OpeningBalance $b) => $b->outstanding());
        $paid = (float) $balances->sum(function (OpeningBalance $b) {
            return max(0.0, round((float) $b->amount - $b->outstanding(), 2));
        });

        return [
            'count' => $balances->filter(fn (OpeningBalance $b) => $b->outstanding() > 0)->count(),
            'total' => round((float) $balances->sum('amount'), 2),
            'outstanding' => round($outstanding, 2),
            'paid' => round($paid, 2),
        ];
    }

    private function formatBalance(OpeningBalance $balance): array
    {
        $fee = $balance->sourceStudentFee;

        return [
            'opening_balance_id' => $balance->id,
            'student_fee_id' => $fee?->id,
            'source_year_id' => $fee?->enrollment?->academic_year_id,
            'description' => $fee?->description ?? ($fee?->feeType?->name_ar ?? 'متخلّد قديم'),
            'amount' => (float) $balance->amount,
            'paid' => round((float) $balance->amount - $balance->outstanding(), 2),
            'outstanding' => $balance->outstanding(),
        ];
    }
}
