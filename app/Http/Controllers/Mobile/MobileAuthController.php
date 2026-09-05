<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\FamilyService;
use App\Services\Mobile\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| MobileAuthController — دخول الوليّ بالهاتف + OTP
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. لا يمسّ AuthController القائم. المعلّم يدخل عبر /api/login
| العادي (حساب تُنشئه الإدارة مربوطاً بصفّه في employees).
|
| request-otp: يطبّع الهاتف، يتأكّد أن ثمّة تلميذاً بهذا الهاتف (لا نُصدر رموزاً
|              لأرقام لا أبناء لها)، ثم يُصدر رمزاً.
| verify-otp:  يتحقّق من الرمز، يُنشئ/يجلب مستخدم parent، ويُصدر Sanctum token
|              بنفس شكل استجابة AuthController (access_token/token_type/user).
|
*/

class MobileAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function requestOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:6|max:20',
        ]);

        $phone = FamilyService::normalizePhone($request->phone);

        if (! $phone) {
            return response()->json(['message' => 'رقم هاتف غير صالح'], 422);
        }

        if (! $this->phoneHasChildren($phone)) {
            // لا نكشف الفرق بين «لا أبناء» و«رقم خاطئ» تفادياً لتعداد الأرقام،
            // لكن لا نُصدر رمزاً فعلياً. الرسالة موحّدة.
            return response()->json([
                'message' => 'إن كان الرقم مسجّلاً مع تلميذ، فسيصلك رمز التحقّق.',
            ]);
        }

        $result = $this->otp->issue($phone);

        $payload = [
            'message' => 'إن كان الرقم مسجّلاً مع تلميذ، فسيصلك رمز التحقّق.',
        ];

        // وضع الإطلاق (manual): يُعاد الرمز ليمنحه القابض/الإدارة للوليّ.
        // يُعطَّل تلقائياً متى ضُبط مزوّد SMS (channel != manual).
        if (config('services.otp.channel', 'manual') === 'manual') {
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:6|max:20',
            'code' => 'required|string|size:6',
        ]);

        $phone = FamilyService::normalizePhone($request->phone);

        if (! $phone || ! $this->otp->verify($phone, $request->code)) {
            return response()->json(['message' => 'الرمز غير صحيح أو منتهي الصلاحية'], 401);
        }

        if (! $this->phoneHasChildren($phone)) {
            return response()->json(['message' => 'لا يوجد تلميذ مسجّل بهذا الرقم'], 403);
        }

        $user = $this->resolveParentUser($phone);

        if (! $user->is_active) {
            return response()->json(['message' => 'هذا الحساب موقوف، تواصل مع المسؤول'], 403);
        }

        $effectivePermissions = $user->getEffectivePermissionNames();
        $token = $user->createToken('mobile_parent', $effectivePermissions)->plainTextToken;

        $userArray = $user->toArray();
        $userArray['effective_permissions'] = $effectivePermissions;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $userArray,
        ]);
    }

    /**
     * هل يوجد تلميذ يطابق هاتف وليّه/أمّه هذا الرقم المطبَّع؟
     */
    private function phoneHasChildren(string $normalizedPhone): bool
    {
        return Student::query()
            ->select('id', 'guardian_phone', 'mother_phone')
            ->get()
            ->contains(fn (Student $st) => FamilyService::normalizePhone($st->guardian_phone) === $normalizedPhone
                || FamilyService::normalizePhone($st->mother_phone) === $normalizedPhone);
    }

    /**
     * يجلب مستخدم الوليّ لهذا الهاتف أو يُنشئه عند أوّل دخول، بدور parent.
     */
    private function resolveParentUser(string $normalizedPhone): User
    {
        $parentRole = Role::where('name', 'parent')->first();

        if (! $parentRole) {
            abort(500, 'دور الوليّ غير مُهيّأ — شغّل MobileRolesSeeder.');
        }

        // نطابق على آخر 8 أرقام مخزّنة في users.phone (تُخزَّن مطبّعة عند الإنشاء).
        $user = User::where('role_id', $parentRole->id)
            ->where('phone', $normalizedPhone)
            ->first();

        if ($user) {
            return $user;
        }

        return User::create([
            'first_name' => 'وليّ',
            'last_name' => $normalizedPhone,
            'username' => 'parent_'.$normalizedPhone,
            'email' => 'parent_'.$normalizedPhone.'@parent.local',
            'phone' => $normalizedPhone,
            'password' => Hash::make(Str::random(40)),
            'role_id' => $parentRole->id,
            'is_active' => true,
        ]);
    }
}
