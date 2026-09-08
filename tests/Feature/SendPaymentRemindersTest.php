<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentReminders;
use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Section;
use App\Models\Student;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * سيناريو التنبيهات الشهرية كاملاً: المتخلّد يُنبَّه، الخالص لا يُنبَّه،
 * المعفى لا يُنبَّه، والفشل يُسجَّل ولا يوقف الموجة.
 */
class SendPaymentRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
    }

    private function makeEnrolledStudent(string $phone, string $firstName = 'أحمد'): Enrollment
    {
        $level = Level::firstOrCreate(['name' => 'الأولى'], ['code' => 'L1']);
        $section = Section::firstOrCreate(
            ['level_id' => $level->id, 'name' => 'أ'],
            ['code' => 'A1']
        );

        $student = Student::create([
            'first_name' => $firstName,
            'last_name' => 'التلميذ',
            'gender' => 'male',
            'guardian_phone' => $phone,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->makeYear()->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);
    }

    /** متخلّد واحد: يصله التنبيه ويُسجَّل في payment_reminders. */
    public function test_unpaid_student_receives_reminder(): void
    {
        $enrollment = $this->makeEnrolledStudent('21609815');

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldReceive('send')
            ->once()
            ->with('21609815', Mockery::pattern('/سبتمبر.*أحمد/su'))
            ->andReturn(true);
        $this->app->instance(SmsProviderInterface::class, $sms);

        (new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'))->handle($sms);

        $this->assertDatabaseHas('payment_reminders', [
            'student_id' => $enrollment->student_id,
            'fee_month' => '2026-09',
            'type' => 'first',
            'sent' => true,
        ]);
    }

    /** الخالص (دفعة فعّالة بقيد نقدي) لا يصله تنبيه. */
    public function test_paid_student_is_not_reminded(): void
    {
        $enrollment = $this->makeEnrolledStudent('20111222');

        Payment::create([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount' => 60,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-10',
            'method' => 'cash',
        ]);

        CashTransaction::create([
            'source_type' => (new Payment)->getMorphClass(),
            'source_id' => Payment::first()->id,
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
            'direction' => 'in',
            'amount' => 60,
            'transaction_date' => '2026-09-10',
        ]);

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsProviderInterface::class, $sms);

        (new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'))->handle($sms);

        $this->assertDatabaseMissing('payment_reminders', [
            'student_id' => $enrollment->student_id,
        ]);
    }

    /** المعفى إعفاءً كاملاً (full_waiver) لا يصله تنبيه — ليس مديناً. */
    public function test_fully_waived_student_is_not_reminded(): void
    {
        $enrollment = $this->makeEnrolledStudent('20445566');

        MonthlyDiscount::create([
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $enrollment->academic_year_id,
            'discount_type' => MonthlyDiscount::TYPE_FULL_WAIVER,
            'monthly_amount' => 0,
            'start_month' => '2026-09',
            'end_month' => '2027-06',
            'reason' => 'حالة اجتماعية',
        ]);

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsProviderInterface::class, $sms);

        (new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'))->handle($sms);

        $this->assertDatabaseMissing('payment_reminders', [
            'student_id' => $enrollment->student_id,
        ]);
    }

    /** فشل الإرسال يُسجَّل بالسبب ولا يرمي استثناء يوقف بقية الموجة. */
    public function test_send_failure_is_recorded_not_thrown(): void
    {
        $enrollment = $this->makeEnrolledStudent('20778899');

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldReceive('send')->once()->andReturn(false);
        $this->app->instance(SmsProviderInterface::class, $sms);

        (new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'))->handle($sms);

        $this->assertDatabaseHas('payment_reminders', [
            'student_id' => $enrollment->student_id,
            'sent' => false,
        ]);
        $this->assertNotNull(
            PaymentReminder::where('student_id', $enrollment->student_id)->first()->failure_reason
        );
    }

    /** تلميذ بلا هاتف وليّ: لا إرسال، ويُسجَّل السبب بوضوح. */
    public function test_student_without_phone_is_recorded_as_failed(): void
    {
        $enrollment = $this->makeEnrolledStudent('');

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsProviderInterface::class, $sms);

        (new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'))->handle($sms);

        $this->assertDatabaseHas('payment_reminders', [
            'student_id' => $enrollment->student_id,
            'sent' => false,
            'failure_reason' => 'لا يوجد هاتف وليّ مسجّل',
        ]);
    }

    /** لا إرسال مزدوج: نفس النوع لنفس الشهر لا يُكرَّر. */
    public function test_same_type_month_is_not_sent_twice(): void
    {
        $enrollment = $this->makeEnrolledStudent('21609815');

        $sms = Mockery::mock(SmsProviderInterface::class);
        $sms->shouldReceive('send')->once()->andReturn(true);
        $this->app->instance(SmsProviderInterface::class, $sms);

        $job = new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09');
        $job->handle($sms);
        $job->handle($sms); // تشغيل ثانٍ لنفس الموجة

        $this->assertSame(
            1,
            PaymentReminder::where('student_id', $enrollment->student_id)
                ->where('type', 'first')
                ->count()
        );
    }
}
