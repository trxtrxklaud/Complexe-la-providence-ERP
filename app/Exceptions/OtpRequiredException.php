<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * يُرمى عند محاولة دخول الوليّ عبر login-by-phone دون رمز تحقّق (OTP).
 * الوليّ لا يدخل برقم الهاتف وحده أبداً — الرقم شبه معلوم داخل المدرسة،
 * فالرمز وحده يُثبت حيازة الهاتف. يستجيب القناة بـ 401 وعَلَم
 * otp_required:true ليطلب العميل رمزاً ثم يعيد المحاولة مع otp_code.
 */
class OtpRequiredException extends RuntimeException
{
    public function __construct(?string $message = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            $message ?? 'رمز التحقق مطلوب لتسجيل دخول أولياء الأمور. اطلب رمزاً عبر request-otp ثم أعد المحاولة مع otp_code.',
            $code,
            $previous
        );
    }
}
