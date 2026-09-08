<?php

namespace App\Services\Sms;

/**
 * عقد موحّد لمزوّدي الرسائل القصيرة (SMS).
 * التطبيق لا يعرف Twilio إلا عبر هذا العقد — لتبديل المزوّد
 * (مزوّد محلي تونسي لاحقاً مثلاً) يُكتب محوّل جديد ويُربط
 * في AppServiceProvider دون لمس أي مستهلك.
 */
interface SmsProviderInterface
{
    /**
     * إرسال رسالة نصية إلى رقم هاتف.
     *
     * @param  string  $to  الرقم بصيغة دولية (مثال: +21621609815).
     * @param  string  $message  نص الرسالة (عربي عادةً — يُرسل UTF-8).
     * @return bool نجاح الإرسال (false يعني فشلاً مُسجَّلاً لا استثناءً).
     */
    public function send(string $to, string $message): bool;
}
