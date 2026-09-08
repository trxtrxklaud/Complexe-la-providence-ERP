<?php

namespace App\Services\Sms;

use App\Services\FamilyService;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

/**
 * مزوّد الرسائل القصيرة عبر Twilio.
 *
 * مطابقة الرقم عبر FamilyService::normalizePhone — نفس مرجع دخول
 * الوليّ ونطاقه في كل النظام (أرقام المدرسة مخزّنة بصيغ مختلفة)،
 * ثم إسباقه بمفتاح تونس 216 لصيغة E.164 المطلوبة من Twilio.
 * أي رقم لا يصمد أمام التطبيع لا يُرسل أصلاً ولا يصل المزوّد.
 */
class TwilioService implements SmsProviderInterface
{
    /** مفتاح تونس الدولي — سوق المدرسة الوحيد. */
    private const TUNISIA_CODE = '216';

    /** الحد الأقصى لطول الرسالة قبل التقسيم — احتياطي وقائي. */
    private const MAX_MESSAGE_LENGTH = 480;

    /** أقل طول مقبول للرقم الوطني بعد التطبيع — أقل من ذلك خطأ إدخال لا يُرسل. */
    private const MIN_NATIONAL_LENGTH = 8;

    public function __construct(
        private readonly Client $client,
        private readonly string $fromNumber,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            new Client(config('services.twilio.sid'), config('services.twilio.token')),
            (string) config('services.twilio.from'),
        );
    }

    public function send(string $to, string $message): bool
    {
        // normalizePhone هو مرجع المطابقة في كل النظام — نفس منطق دخول
        // الوليّ ونطاقه. صيغة مختلفة هنا تعني رسائل تضيع أو تصل غريباً.
        $national = FamilyService::normalizePhone($to);

        if ($national === null || strlen($national) < self::MIN_NATIONAL_LENGTH) {
            Log::warning('Twilio SMS skipped: invalid phone number', ['to' => $to]);

            return false;
        }

        $e164 = '+'.self::TUNISIA_CODE.$national;

        if (trim($message) === '') {
            Log::warning('Twilio SMS skipped: empty message', ['to' => $e164]);

            return false;
        }

        $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);

        try {
            $this->client->messages->create($e164, [
                'from' => $this->fromNumber,
                'body' => $message,
            ]);

            return true;
        } catch (TwilioException $e) {
            Log::error('Twilio SMS failed: '.$e->getMessage(), ['to' => $e164]);

            return false;
        }
    }
}
