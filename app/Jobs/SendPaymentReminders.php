<?php

namespace App\Jobs;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * إرسال تنبيهات الدفع الشهرية لأولياء الأمور المتخلّدين — عبر الطابور.
 *
 * تعريف «غير خالص» هو نفسه تعريف تقرير المتخلّدين (UnpaidMonthlyReport):
 * قسط الشهر لا توجد له دفعة فعّالة بقيد نقدي غير ملغى (الدفتر مصدر الحقيقة)،
 * ولا إعفاء كامل (full_waiver) يغطي الشهر. المخفّض جزئياً يُنبَّه بالمتبقّي.
 *
 * الأصناف: first (لطف)، mid (تنبيه)، final (إنذار أخير) — لكل تلميذ
 * وشهر واحد على الأكثر من كل صنف (قيد فريد في payment_reminders).
 * لا يُرسل التنبيه إلى من دفع أو أُعفي — والفشل يُسجَّل ولا يوقف البقية.
 */
class SendPaymentReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $type = PaymentReminder::TYPE_FIRST,
        public ?string $feeMonth = null,
    ) {}

    public function handle(SmsProviderInterface $sms): void
    {
        $month = $this->feeMonth ?? now()->format('Y-m');
        $year = AcademicYear::query()->where('is_active', true)->first();

        if (! $year) {
            Log::warning('SendPaymentReminders skipped: no active academic year');

            return;
        }

        if (! in_array($this->type, PaymentReminder::TYPES, true)) {
            Log::warning('SendPaymentReminders skipped: unknown type', ['type' => $this->type]);

            return;
        }

        $alreadySentStudentIds = PaymentReminder::query()
            ->where('fee_month', $month)
            ->where('type', $this->type)
            ->pluck('student_id')
            ->all();

        $waivedEnrollmentIds = MonthlyDiscount::query()
            ->where('academic_year_id', $year->id)
            ->where('discount_type', MonthlyDiscount::TYPE_FULL_WAIVER)
            ->active()
            ->where('start_month', '<=', $month)
            ->where('end_month', '>=', $month)
            ->pluck('enrollment_id')
            ->all();

        $paymentMorph = (new Payment)->getMorphClass();
        $monthEnd = Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString();

        // المدفوع فعلاً: دفعة غير ملغاة بشهر مطابقة وخلفها قيد نقدي فعّال.
        $paidEnrollmentIds = Payment::query()
            ->whereNull('payments.cancelled_at')
            ->whereJsonContains('payments.months', $month)
            ->whereExists(function ($query) use ($paymentMorph) {
                $query->selectRaw('1')
                    ->from('cash_transactions')
                    ->whereColumn('cash_transactions.source_id', 'payments.id')
                    ->where('cash_transactions.source_type', $paymentMorph)
                    ->where('cash_transactions.category', CashTransaction::CATEGORY_MONTHLY_FEE)
                    ->whereNull('cash_transactions.cancelled_at');
            })
            ->pluck('payments.enrollment_id')
            ->filter()
            ->unique()
            ->all();

        $enrollments = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('status', 'active')
            ->whereDate('enrollment_date', '<=', $monthEnd)
            ->whereNotIn('id', array_merge($paidEnrollmentIds, $waivedEnrollmentIds))
            ->whereNotIn('student_id', $alreadySentStudentIds)
            ->with([
                'student:id,first_name,last_name,guardian_phone,mother_phone,guardian_first_name,guardian_last_name',
                'monthlyDiscounts' => fn ($query) => $query->active()
                    ->where('start_month', '<=', $month)
                    ->where('end_month', '>=', $month),
            ])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->unique('student_id');

        $sent = 0;
        $failed = 0;

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            // الوليّ أولاً ثم الأم — نفس أولوية قوائم التحصيل.
            $phone = trim((string) ($student->guardian_phone ?: $student->mother_phone ?: ''));

            if ($phone === '') {
                self::record($enrollment, $month, $this->type, '', false, 'لا يوجد هاتف وليّ مسجّل');
                $failed++;

                continue;
            }

            $ok = $sms->send($phone, $this->buildMessage($student->first_name, $month));
            $ok ? $sent++ : $failed++;

            self::record($enrollment, $month, $this->type, $phone, $ok, $ok ? null : 'فشل إرسال الرسالة عبر المزوّد');
        }

        Log::info('SendPaymentReminders finished', [
            'month' => $month,
            'type' => $this->type,
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    private static function record(
        Enrollment $enrollment,
        string $month,
        string $type,
        string $phone,
        bool $sent,
        ?string $reason,
    ): void {
        try {
            PaymentReminder::create([
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'academic_year_id' => $enrollment->academic_year_id,
                'fee_month' => $month,
                'type' => $type,
                'phone' => $phone !== '' ? $phone : '—',
                'sent' => $sent,
                'failure_reason' => $reason,
                'sent_at' => $sent ? now() : null,
            ]);
        } catch (QueryException $e) {
            // تعارض الفهرس الفريد: أُرسل التنبيه نفسه لهذا التلميذ قبل لحظة
            // (تداخل تشغيلين) — لا شيء يُفعل.
        }
    }

    /**
     * الرسالة بالعربية التونسية المتّبعة في المدرسة: ودودة أولاً،
     * متدرّجة في الحزم مع final. الشهر باسمه العربي المحلي.
     */
    private function buildMessage(string $studentFirstName, string $month): string
    {
        $monthName = self::MONTH_NAMES_AR[substr($month, 5, 2)] ?? $month;

        return match ($this->type) {
            PaymentReminder::TYPE_FIRST => "وليّ الأمر الكريم، نذكّركم بمواعيد خلاص معلوم شهر {$monthName} الخاص بتلميذكم {$studentFirstName}. شكراً لتعاونكم — إدارة مدرسة لا بروفيدانس.",
            PaymentReminder::TYPE_MID => "وليّ الأمر الكريم، ما زال معلوم شهر {$monthName} الخاص بتلميذكم {$studentFirstName} غير مسدّد. نرجو التفضّل بالخلاص عند القابض. شكراً — إدارة مدرسة لا بروفيدانس.",
            PaymentReminder::TYPE_FINAL => "وليّ الأمر الكريم، تأخّر خلاص معلوم شهر {$monthName} الخاص بتلميذكم {$studentFirstName}. نرجو التسديد في أقرب وقت لتجنّب إيقاف الخدمات. إدارة مدرسة لا بروفيدانس.",
            default => 'تذكير بموعد خلاص المعاليم — إدارة مدرسة لا بروفيدانس.',
        };
    }

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس', '04' => 'أفريل',
        '05' => 'ماي', '06' => 'جوان', '07' => 'جويلية', '08' => 'أوت',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];
}
