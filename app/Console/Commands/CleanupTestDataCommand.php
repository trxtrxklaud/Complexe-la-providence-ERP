<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\ClubMonthlyFee;
use App\Models\Guardian;
use App\Models\Payment;
use App\Services\ClubService;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupTestDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:test-data '
        . '{--apply : تنفيذ الإلغاء الموثق للبيانات التجريبية بدل العرض فقط} '
        . '{--inspect-invalid-unpaid-club-fees : عرض مرشحي النوادي غير المدفوعة خارج نطاق السنة (قراءة فقط)} '
        . '{--apply-invalid-unpaid-club-fees= : إلغاء موثق لمعاليم نادي محددة غير مدفوعة خارج نطاق السنة؛ تُعطى IDs صريحة مفصولة بفواصل مثل 15,16,17}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'فحص البيانات التجريبية وعرضها (قراءة فقط افتراضياً) مع إلغاء موثق اختياري عبر --apply';

    /**
     * Execute the console command.
     */
    public function handle(LedgerService $ledger): int
    {
        // كشف الحضور الحقيقي للخيار حتى لو أُعطي بلا قيمة
        // (VALUE_OPTIONAL يجعل option() تُرجع null للعلم المجرد).
        if ($this->input->hasParameterOption('--apply-invalid-unpaid-club-fees')) {
            return $this->applyInvalidUnpaidClubFees();
        }

        if ($this->option('inspect-invalid-unpaid-club-fees')) {
            return $this->inspectInvalidUnpaidClubFees();
        }

        if ($this->option('apply')) {
            return $this->apply($ledger);
        }

        return $this->dryRun();
    }

    /**
     * وضع القراءة فقط: يعرض المرشحين دون أي تغيير في قاعدة البيانات.
     */
    private function dryRun(): int
    {
        $this->info('=== عرض البيانات التجريبية المرشحة (قراءة فقط) ===');

        // 1. دفعات تجريبية: بمبلغ صفر أو بلا توزيعات.
        $candidatePayments = Payment::query()
            ->whereNull('cancelled_at')
            ->where(function ($q) {
                $q->where('amount', '<=', 0)
                    ->orWhereDoesntHave('paymentAllocations');
            })
            ->with(['paymentAllocations', 'student:id,first_name,last_name'])
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->info('— دفعات تجريبية مرشحة (بمبلغ صفر أو بلا توزيعات)');

        if ($candidatePayments->isEmpty()) {
            $this->line('  (لا شيء)');
        }

        foreach ($candidatePayments as $payment) {
            $studentName = $payment->student
                ? trim($payment->student->first_name.' '.$payment->student->last_name)
                : '—';
            $this->line(sprintf(
                '  payment id=%d | amount=%s | date=%s | method=%s | student=%s',
                $payment->id,
                $payment->amount,
                $payment->payment_date?->toDateString(),
                $payment->method ?? '—',
                $studentName
            ));

            $allocations = $payment->paymentAllocations;
            if ($allocations->isEmpty()) {
                $this->line('    allocations: (بلا توزيعات)');
            } else {
                foreach ($allocations as $alloc) {
                    $this->line(sprintf(
                        '    allocation id=%d | student_fee_id=%d | amount=%s',
                        $alloc->id,
                        $alloc->student_fee_id,
                        $alloc->amount_allocated
                    ));
                }
            }

            $cashRows = CashTransaction::query()
                ->where('source_type', (new Payment)->getMorphClass())
                ->where('source_id', $payment->id)
                ->get();

            if ($cashRows->isEmpty()) {
                $this->line('    cash_transactions: (لا أسطر)');
            } else {
                foreach ($cashRows as $row) {
                    $this->line(sprintf(
                        '    cash id=%d | category=%s | amount=%s | cancelled=%s',
                        $row->id,
                        $row->category,
                        $row->amount,
                        $row->cancelled_at ? 'نعم' : 'لا'
                    ));
                }
            }
        }

        // 2. أولياء مكررون بعد تطبيع الهاتف.
        $duplicates = $this->duplicateGuardians();

        $this->newLine();
        $this->info('— أولياء مكررون بعد تطبيع الهاتف');

        if (empty($duplicates)) {
            $this->line('  (لا شيء)');
        }

        foreach ($duplicates as $phone => $group) {
            $this->line('  هاتف مطبّع: '.$phone);
            foreach ($group as $g) {
                $this->line(sprintf(
                    '    guardian id=%d | %s %s | phone=%s',
                    $g->id,
                    $g->first_name,
                    $g->last_name,
                    $g->phone
                ));
            }
        }

        // 3. معاليم نوادٍ خارج نطاق السنة الدراسية.
        $outOfRange = $this->outOfRangeClubMonthlyFees();

        $this->newLine();
        $this->info('— club_monthly_fees خارج نطاق أشهر السنة الدراسية');
        $this->line('  أشهر السنة الدراسية تُحسب ديناميكيًا من بداية/نهاية كل سنة دراسية.');

        $validByYear = $this->validMonthsByYear();
        foreach ($validByYear as $yearId => $months) {
            $this->line(sprintf('  سنة %d: %s', $yearId, implode(', ', $months)));
        }

        if (empty($outOfRange)) {
            $this->line('  (لا شيء)');
        }

        foreach ($outOfRange as $row) {
            $kind = $this->feeKind($row);
            $this->line(sprintf(
                '  club_monthly_fee id=%d | student_id=%d | club_id=%d | month=%s | academic_year_id=%d | status=%s | %s',
                $row->id,
                $row->student_id,
                $row->club_id,
                $row->month,
                $row->academic_year_id,
                $row->status,
                $kind
            ));
        }

        $this->newLine();
        $this->warn('Dry-run only. No data was changed.');

        return self::SUCCESS;
    }

    /**
     * وضع --apply: إلغاء موثق للدفعات التجريبية فقط داخل معاملة واحدة.
     * لا يُحذف أي سجل ولا يُلغى ولي ولا رسم نادي.
     */
    private function apply(LedgerService $ledger): int
    {
        return DB::transaction(function () use ($ledger) {
            $this->info('=== تنفيذ الإلغاء الموثق للدفعات التجريبية ===');

            $candidatePayments = Payment::query()
                ->whereNull('cancelled_at')
                ->where(function ($q) {
                    $q->where('amount', '<=', 0)
                        ->orWhereDoesntHave('paymentAllocations');
                })
                ->orderBy('id')
                ->get();

            if ($candidatePayments->isEmpty()) {
                $this->line('  (لا دفعات تجريبية لإلغائها)');
            }

            $cancelled = 0;
            $reason = 'بيانات تجريبية — تنظيف بأمر cleanup:test-data';

            foreach ($candidatePayments as $payment) {
                $payment->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => null,
                    'cancellation_reason' => $reason,
                ]);

                // إلغاء أسطر الدفتر النقدي المرتبطة بالدفعة (إلغاء موثق لا حذف).
                $ledger->cancelFor($payment, null, $reason);

                $cancelled++;
                $this->line(sprintf('  أُلغيت الدفعة id=%d بإلغاء موثق', $payment->id));
            }

            $this->newLine();
            $this->info(sprintf('تم إلغاء %d دفعة تجريبية بإلغاء موثق. لا حذف لأي سجل.', $cancelled));

            return self::SUCCESS;
        });
    }

    /**
     * تجميع الأولياء المكررين بعد تطبيع الهاتف.
     *
     * @return array<string, array<int, Guardian>>
     */
    private function duplicateGuardians(): array
    {
        $guardians = Guardian::query()->get(['id', 'first_name', 'last_name', 'phone']);

        $groups = [];
        foreach ($guardians as $g) {
            $normalized = $this->normalizePhone($g->phone);
            if ($normalized === '') {
                continue;
            }
            $groups[$normalized][] = $g;
        }

        return array_filter($groups, fn ($group) => count($group) > 1);
    }

    /**
     * تطبيع هاتف تونسي إلى 8 أرقام: يزيل +216/00216 والصفر البادئ والفواصل.
     */
    private function normalizePhone(?string $phone): string
    {
        if ($phone === null || trim($phone) === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00216')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '216') && strlen($digits) === 11) {
            $digits = substr($digits, 3);
        }

        return ltrim($digits, '0');
    }

    /**
     * معاليم النوادي المعلّقة خارج نطاق أشهر السنة الدراسية المسجّلة.
     *
     * @return array<int, ClubMonthlyFee>
     */
    private function outOfRangeClubMonthlyFees(): array
    {
        $years = AcademicYear::query()->get(['id', 'name', 'start_date', 'end_date']);

        $rows = [];
        foreach ($years as $year) {
            $validMonths = $this->validMonthsFor($year);

            $fees = ClubMonthlyFee::query()
                ->where('academic_year_id', $year->id)
                ->get(['id', 'student_id', 'club_id', 'academic_year_id', 'month', 'status']);

            foreach ($fees as $fee) {
                if (! in_array($fee->month, $validMonths, true)) {
                    $rows[] = $fee;
                }
            }
        }

        return $rows;
    }

    /**
     * تصنيف معلوم خارج النطاق: المدفوع يحتاج مراجعة ولا يُلغى، غير المدفوع مرشح للتصحيح/الإلغاء.
     */
    private function feeKind(ClubMonthlyFee $fee): string
    {
        return in_array($fee->status, [ClubMonthlyFee::STATUS_PAID, ClubMonthlyFee::STATUS_PARTIAL], true)
            ? 'محتاج مراجعة — لا يُلغى'
            : 'غير مدفوع — مرشح بعد المراجعة';
    }

    /**
     * أشهر السنة الدراسية بصيغة Y-m عبر النطاق الرسمي المعتمد (سبتمبر–مايو).
     *
     * @return array<int, string>
     */
    private function validMonthsFor(AcademicYear $year): array
    {
        return app(ClubService::class)->getAcademicYearMonths($year->id);
    }

    /**
     * الأشهر المعتمدة لكل سنة دراسية.
     *
     * @return array<int, array<int, string>>
     */
    private function validMonthsByYear(): array
    {
        $map = [];
        foreach (AcademicYear::query()->get(['id']) as $year) {
            $map[$year->id] = $this->validMonthsFor($year);
        }

        return $map;
    }

    /**
     * عرض قراءة فقط لمرشحي معاليم النوادي غير المدفوعة خارج نطاق السنة.
     */
    private function inspectInvalidUnpaidClubFees(): int
    {
        $this->info('=== عرض مرشحي النوادي غير المدفوعة خارج نطاق السنة (قراءة فقط) ===');

        $validByYear = $this->validMonthsByYear();
        foreach ($validByYear as $yearId => $months) {
            $this->line(sprintf('  سنة %d معتمدة: %s', $yearId, implode(', ', $months)));
        }

        $candidates = $this->invalidUnpaidClubFeeCandidates();

        if (empty($candidates)) {
            $this->line('  (لا مرشحين)');
        }

        foreach ($candidates as $fee) {
            $this->line(sprintf(
                '  club_monthly_fee id=%d | student=%d | club=%d | month=%s | year=%d | status=%s | amount_paid=%s',
                $fee->id,
                $fee->student_id,
                $fee->club_id,
                $fee->month,
                $fee->academic_year_id,
                $fee->status,
                $fee->amount_paid
            ));
        }

        $this->newLine();
        $this->info(sprintf('العدد الإجمالي للمرشحين: %d', count($candidates)));
        $this->warn('Read-only inspection. No data was changed.');

        return self::SUCCESS;
    }

    /**
     * إلغاء موثق لمعاليم نادي محددة غير مدفوعة خارج نطاق السنة، عبر IDs صريحة فقط.
     *
     * يُرفض التنفيذ كاملاً دون تعديل أي سجل إذا كان أي ID لا يطابق كل الشروط.
     */
    private function applyInvalidUnpaidClubFees(): int
    {
        $raw = trim((string) $this->option('apply-invalid-unpaid-club-fees'));
        $ids = $this->parseFeeIds($raw);

        if ($ids === []) {
            $this->error('يجب تمرير قائمة IDs صريحة مفصولة بفواصل، مثال: --apply-invalid-unpaid-club-fees=15,16,17');

            return self::FAILURE;
        }

        $validationError = $this->validateFeeCandidates($ids);
        if ($validationError !== null) {
            $this->error('رُفضت العملية كاملة دون تعديل أي سجل: '.$validationError);

            return self::FAILURE;
        }

        $this->info('=== إلغاء موثق لمعاليم النوادي غير المدفوعة خارج نطاق السنة ===');
        $this->line(sprintf('قائمة المرشحين (%d): %s', count($ids), implode(', ', $ids)));

        return DB::transaction(function () use ($ids) {
            $reason = 'شهر خارج نطاق السنة الدراسية — تنظيف بأمر cleanup:test-data';

            $processed = 0;
            foreach ($ids as $id) {
                ClubMonthlyFee::whereKey($id)->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => null,
                    'cancellation_reason' => $reason,
                ]);
                $processed++;
            }

            $this->newLine();
            $this->info(sprintf('تمت معالجة %d سجلاً بإلغاء موثق. لا حذف لأي سجل.', $processed));

            return self::SUCCESS;
        });
    }

    /**
     * تحليل قائمة IDs من نص مفصول بفواصل.
     *
     * @return array<int, int>
     */
    private function parseFeeIds(string $raw): array
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($p) => $p !== '');
        $ids = array_map('intval', $parts);

        return array_values(array_filter($ids, fn ($id) => $id > 0));
    }

    /**
     * تحقق أن كل ID يطابق شروط المرشح بدقة. يرفض بأول خطأ.
     */
    private function validateFeeCandidates(array $ids): ?string
    {
        $morph = (new ClubMonthlyFee)->getMorphClass();
        $validByYear = $this->validMonthsByYear();

        foreach ($ids as $id) {
            $fee = ClubMonthlyFee::find($id);

            if (! $fee) {
                return "السجل id={$id} غير موجود";
            }

            if ($fee->cancelled_at !== null) {
                return "السجل id={$id} ملغى مسبقاً";
            }

            if (! isset($validByYear[$fee->academic_year_id])) {
                return "السجل id={$id} يخص سنة دراسية غير مسجّلة";
            }

            if (in_array($fee->month, $validByYear[$fee->academic_year_id], true)) {
                return "السجل id={$id} شهره {$fee->month} داخل نطاق السنة المعتمدة";
            }

            if ($fee->status !== ClubMonthlyFee::STATUS_UNPAID) {
                return "السجل id={$id} حالته {$fee->status} وليست unpaid";
            }

            if ((float) $fee->amount_paid !== 0.0) {
                return "السجل id={$id} عليه مبلغ مدفوع {$fee->amount_paid}";
            }

            $hasAllocations = $fee->studentFee()
                ->whereHas('paymentAllocations')
                ->exists();
            if ($hasAllocations) {
                return "السجل id={$id} له توزيعات مدفوعات مرتبطة";
            }

            $hasCash = CashTransaction::query()
                ->where('source_type', $morph)
                ->where('source_id', $fee->id)
                ->exists();
            if ($hasCash) {
                return "السجل id={$id} له أسطر خزينة مرتبطة";
            }
        }

        return null;
    }

    /**
     * مرشحو معاليم النوادي: شهر خارج النطاق + غير مدفوع + مبلغ صفر
     * + بلا توزيعات + بلا خزينة + غير ملغى.
     *
     * @return array<int, ClubMonthlyFee>
     */
    private function invalidUnpaidClubFeeCandidates(): array
    {
        $validByYear = $this->validMonthsByYear();
        $morph = (new ClubMonthlyFee)->getMorphClass();

        $rows = [];
        foreach ($validByYear as $yearId => $validMonths) {
            $fees = ClubMonthlyFee::query()
                ->where('academic_year_id', $yearId)
                ->whereNull('cancelled_at')
                ->where('status', ClubMonthlyFee::STATUS_UNPAID)
                ->where('amount_paid', 0)
                ->whereDoesntHave('studentFee.paymentAllocations')
                ->whereNotExists(function ($q) use ($morph) {
                    $q->select(DB::raw(1))
                        ->from('cash_transactions')
                        ->where('source_type', $morph)
                        ->whereColumn('source_id', 'club_monthly_fees.id');
                })
                ->orderBy('id')
                ->get(['id', 'student_id', 'club_id', 'academic_year_id', 'month', 'status', 'amount_paid']);

            foreach ($fees as $fee) {
                if (! in_array($fee->month, $validMonths, true)) {
                    $rows[] = $fee;
                }
            }
        }

        return $rows;
    }
}