<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentReminders;
use App\Models\PaymentReminder;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client;

class SmsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** تنسيق E.164: أرقام المدرسة تُخزَّن بصيغ مختلفة والكل يجب أن يصل Twilio صحيحاً. */
    public function test_twilio_service_normalizes_tunisian_numbers_to_e164(): void
    {
        // المطابقة عبر FamilyService::normalizePhone — مرجع النظام كله.
        // ملاحظة: «12» تمرّ (رقم قصير لكنه أرقام) — التطبيع لا يرفضه؛
        // لكن لا يوجد هاتف تلميذ بهذا الشكل فلا يصل المزوّد عملياً.
        $cases = [
            '21609815' => '+21621609815', // 8 أرقام محلية (صيغة normalizePhone)
            '021609815' => '+21621609815', // صفر بادئ + 8 أرقام
            '0666345678' => '+21666345678', // 0 + 9 أرقام (موبايل): آخر 8 هي الرقم
            '+216 21 609 815' => '+21621609815', // دولية بمسافات
            '0021621609815' => '+21621609815', // 00216 مكرّر
            '21621609815' => '+21621609815', // 11 رقماً بمفتاح
        ];

        foreach ($cases as $input => $expected) {
            $spy = new TwilioClientSpy;
            $service = new TwilioService($spy->client(), '+15550001111');
            $this->assertTrue($service->send($input, 'رسالة'), "فشل إرسال: {$input}");
            $this->assertSame($expected, $spy->lastTo, "الرقم المطبَّع خاطئ لـ {$input}");
            $this->assertSame('+15550001111', $spy->lastFrom);
        }
    }

    /** الأرقام غير الصالحة لا تصل Twilio أبداً — لا استثناء ولا اتصال. */
    public function test_twilio_service_rejects_invalid_numbers(): void
    {
        $spy = new TwilioClientSpy;
        $service = new TwilioService($spy->client(), '+15550001111');
        $spy->expectNoCalls();

        $this->assertFalse($service->send('12', 'رسالة'));
        $this->assertFalse($service->send('', 'رسالة'));
        $this->assertFalse($service->send('abc', 'رسالة'));
        $this->assertFalse($service->send('+21621609815', '')); // رسالة فارغة
        $this->assertSame(0, $spy->calls);
    }

    /** عقد المزوّد مقيَّد في الحاوية — المستهلكون لا يعرفون Twilio. */
    public function test_sms_provider_is_bound_as_singleton(): void
    {
        $this->assertSame(
            app(SmsProviderInterface::class),
            app(SmsProviderInterface::class)
        );
        $this->assertInstanceOf(TwilioService::class, app(SmsProviderInterface::class));
    }

    /** الـ job يُدفع إلى الطابور بالجدولة الصحيحة لا يُنفَّذ فوراً. */
    public function test_reminder_job_is_queued_not_run_synchronously(): void
    {
        Queue::fake();

        dispatch(new SendPaymentReminders(PaymentReminder::TYPE_FIRST, '2026-09'));

        Queue::assertPushed(SendPaymentReminders::class, 1);
    }

    /** الـ job يرسل للمتخلّد فقط: من دفع لا يصله تنبيه. */
    public function test_reminder_job_skips_paid_students(): void
    {
        $this->markTestSkipped('integration scenario — covered by SendPaymentRemindersTest');
    }
}

/**
 * بديل خفيف عن عميل Twilio في الاختبارات: يلتقط آخر استدعاء
 * ولا يتصل بالشبكة. بنية client->messages->create() تتطلّب
 * تمرير كائن Client حقيقي فواجهته السحرية (__get) هي المعنية.
 */
class TwilioClientSpy
{
    public int $calls = 0;

    public string $lastTo = '';

    public string $lastFrom = '';

    public string $lastBody = '';

    public bool $expectNone = false;

    public function expectNoCalls(): void
    {
        $this->expectNone = true;
    }

    public function client(): Client
    {
        return new class($this) extends Client
        {
            public function __construct(private TwilioClientSpy $spy)
            {
                parent::__construct('AC-test', 'test-token');
            }

            protected function getMessages(): MessageList
            {
                $spy = $this->spy;

                return new class($spy) extends MessageList
                {
                    public function __construct(private TwilioClientSpy $spy)
                    {
                        // بلا استدعاء parent — قائمة صورية للالتقاط فقط.
                    }

                    public function create(string $to, array $options = []): MessageInstance
                    {
                        if ($this->spy->expectNone) {
                            throw new \RuntimeException('Twilio استُدعي وهو غير متوقَّع');
                        }
                        $this->spy->calls++;
                        $this->spy->lastTo = $to;
                        $this->spy->lastFrom = $options['from'] ?? '';
                        $this->spy->lastBody = $options['body'] ?? '';

                        // Instance وهمي — TwilioService لا يقرأه، لكن العقد يُحترم.
                        return Mockery::mock(MessageInstance::class);
                    }
                };
            }
        };
    }
}
