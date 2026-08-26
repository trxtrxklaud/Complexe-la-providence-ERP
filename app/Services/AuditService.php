<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * تسجيل العمليات المهمّة في سجل التدقيق.
 *
 * دفاعيّة عمداً: أيّ فشل في الكتابة (جدول مفقود، خطأ اتصال ...) يُبتلع ويُبلَّغ
 * عبر report() فحسب — تسجيلُ الأثر إضافةٌ لا يجوز أن تُسقِط تسجيل دخولٍ أو دفعةٍ
 * أو أيّ عملية حقيقية. لا تمرّ هذه الخدمة بالدفتر النقدي ولا تحرّك أيّ مال.
 */
class AuditService
{
    /**
     * @param  string  $action  نوع العملية (login, payment.create ...)
     * @param  string  $description  وصف عربي مقروء
     * @param  Model|null  $model  الكائن المرتبط اختياراً (Payment, Student ...)
     * @param  array<string,mixed>  $metadata  بيانات إضافية حرّة
     */
    public static function log(string $action, string $description, ?Model $model = null, array $metadata = []): ?AuditLog
    {
        try {
            $user = Auth::user();

            $userName = $user
                ? trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null
                : null;

            return AuditLog::create([
                'user_id'     => $user?->getKey(),
                'user_name'   => $userName,
                'action'      => $action,
                'model_type'  => $model ? class_basename($model) : null,
                'model_id'    => $model?->getKey(),
                'description' => $description,
                'metadata'    => $metadata !== [] ? $metadata : null,
                'ip_address'  => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
