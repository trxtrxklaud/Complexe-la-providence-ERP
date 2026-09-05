<?php

namespace App\Services\Mobile;

use App\Models\OtpCode;
use App\Services\FamilyService;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| OtpService — إصدار رموز دخول الوليّ والتحقّق منها
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. الرمز يُخزَّن مُجزّأً (Hash) لا نصّاً. الهاتف يُطبَّع
| عبر FamilyService::normalizePhone() (قراءة ثابتة، بلا تعديل).
|
| للإطلاق: القناة الافتراضية "manual" تُرجع الرمز للإدارة/القابض ليمنحه
| للوليّ (بلا تكلفة SMS). لاحقاً يُضاف مزوّد SMS دون تغيير هذا العقد.
|
*/

class OtpService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /**
     * يُصدر رمزاً جديداً للهاتف المطبَّع ويُبطل ما قبله. يُرجع الرمز الخام
     * (لعرضه للإدارة في وضع manual، أو لإرساله عبر SMS لاحقاً).
     */
    public function issue(string $rawPhone): array
    {
        $phone = FamilyService::normalizePhone($rawPhone);

        if (! $phone) {
            throw new \InvalidArgumentException('رقم هاتف غير صالح');
        }

        // إبطال الرموز السابقة غير المستهلكة لنفس الهاتف.
        OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return [
            'phone' => $phone,
            'code' => $code,
            'expires_at' => $otp->expires_at,
        ];
    }

    /**
     * يتحقّق من الرمز. يُرجع true عند النجاح ويُعلّم الرمز مستهلَكاً.
     */
    public function verify(string $rawPhone, string $code): bool
    {
        $phone = FamilyService::normalizePhone($rawPhone);

        if (! $phone) {
            return false;
        }

        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }
}
