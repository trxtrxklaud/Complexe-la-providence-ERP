<?php

use App\Jobs\SendPaymentReminders;
use App\Models\PaymentReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| تنبيهات المعاليم الشهرية عبر SMS
|--------------------------------------------------------------------------
| ثلاث موجات لكل شهر قسطي (اليوم الخامس لطفاً، الخامس عشر تنبيهاً،
| الثالث والعشرون إنذاراً أخيراً قبل نهاية الشهر). التذكير يستهدف
| المتخلّدين فقط — من دفع أو أُعفي لا يصله شيء (انظر SendPaymentReminders).
| تنبيه: التصميم الجدولي يعتمد قيد job واحد لكل موجة؛ يُستحسن الإرسال
| بعد ساعة الذروة (9:00) لا منتصف الليل كي يقرأ الوليّ رسالته.
|
*/
Schedule::job(new SendPaymentReminders(PaymentReminder::TYPE_FIRST))
    ->monthlyOn(5, '09:00')
    ->name('payment-reminders-first')
    ->withoutOverlapping();

Schedule::job(new SendPaymentReminders(PaymentReminder::TYPE_MID))
    ->monthlyOn(15, '09:00')
    ->name('payment-reminders-mid')
    ->withoutOverlapping();

Schedule::job(new SendPaymentReminders(PaymentReminder::TYPE_FINAL))
    ->monthlyOn(23, '09:00')
    ->name('payment-reminders-final')
    ->withoutOverlapping();
