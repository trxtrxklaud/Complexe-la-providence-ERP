<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ParentVerificationMail;
use App\Models\OtpCode;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ParentRegisterController extends Controller
{
    /**
     * طلب رمز التفعيل: يتحقق من وجود رقم الهاتف في ملفات التلاميذ المسجلين بالمدرسة،
     * يولد رمزاً من 6 أرقام، ويرسله إلى بريد Gmail الخاص بالولي.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:8|max:20',
            'email' => 'required|email|max:255',
            'pseudo' => 'required|string|min:3|max:50',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'phone.min' => 'رقم الهاتف غير صالح.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'pseudo.required' => 'اسم المستخدم (Pseudo) مطلوب.',
            'pseudo.min' => 'اسم المستخدم يجب ألا يقل عن 3 أحرف.',
        ]);

        $rawPhone = trim((string) $request->phone);
        $email = trim((string) $request->email);
        $pseudo = trim((string) $request->pseudo);

        $normalizedPhone = FamilyService::normalizePhone($rawPhone);
        if (! $normalizedPhone) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير صالح.',
            ], 422);
        }

        // 1. التحقق من وجود رقم الهاتف في قاعدة بيانات المدرسة لتلميذ مسجل
        $matchingStudents = Student::query()
            ->select('id', 'first_name', 'last_name', 'guardian_first_name', 'guardian_last_name', 'guardian_phone', 'mother_phone')
            ->where(function ($q) {
                $q->whereNotNull('guardian_phone')->orWhereNotNull('mother_phone');
            })
            ->get()
            ->filter(function (Student $st) use ($normalizedPhone) {
                return FamilyService::normalizePhone($st->guardian_phone) === $normalizedPhone
                    || FamilyService::normalizePhone($st->mother_phone) === $normalizedPhone;
            });

        if ($matchingStudents->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف هذا غير مسجل لأي تلميذ في المدرسة. يرجى مراجعة إدارة المدرسة لتسجيل رقمك في ملف التلميذ أولاً.',
            ], 422);
        }

        // 2. التحقق من عدم حجز اسم المستخدم (Pseudo) لمستخدم آخر
        $pseudoTaken = User::query()
            ->where('username', $pseudo)
            ->where('phone', '!=', $normalizedPhone)
            ->exists();

        if ($pseudoTaken) {
            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم (Pseudo) مستخدم بالفعل من قبل شخص آخر، يرجى اختيار اسم مختلف.',
            ], 422);
        }

        // 3. التحقق من عدم استخدام البريد الإلكتروني لحساب شخص آخر
        $emailTaken = User::query()
            ->where('email', $email)
            ->where('phone', '!=', $normalizedPhone)
            ->exists();

        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'البريد الإلكتروني مستخدم بالفعل لحساب آخر.',
            ], 422);
        }

        // 4. توليد رمز التحقق وتخزينه
        OtpCode::where('phone', $normalizedPhone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $normalizedPhone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(15),
        ]);

        // حفظ بيانات التسجيل المؤقتة في الذاكرة
        Cache::put('parent_reg_'.$normalizedPhone, [
            'pseudo' => $pseudo,
            'email' => $email,
            'phone' => $normalizedPhone,
        ], now()->addMinutes(15));

        // 5. إرسال الكود إلى بريد Gmail
        $mailSent = false;
        try {
            Mail::to($email)->send(new ParentVerificationMail($code, $pseudo));
            $mailSent = true;
        } catch (\Throwable $e) {
            Log::warning('Parent registration email sending failed: '.$e->getMessage());
            $mailSent = false;
        }

        $studentNames = $matchingStudents->map(function ($s) {
            return trim(($s->first_name ?? '').' '.($s->last_name ?? ''));
        })->values()->all();

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال رمز التحقق المكون من 6 أرقام إلى بريدك: '.$email,
            'mail_sent' => $mailSent,
            'dev_code' => $code, // متاح دائماً لتسهيل الفحص والتطوير الفوري
            'students_count' => $matchingStudents->count(),
            'students_names' => $studentNames,
        ], 200);
    }

    /**
     * التحقق من الرمز وتفعيل الحساب:
     * يُفعّل الحساب باسم المستخدم (Pseudo)، البريد (Gmail)،
     * وكلمة السر (رقم الهاتف)، ثم يُصدر التوكن ويعيد بيانات الولي وأبنائه.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string|size:6',
            'pseudo' => 'nullable|string',
            'email' => 'nullable|email',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'code.required' => 'رمز التحقق مطلوب.',
            'code.size' => 'رمز التحقق يجب أن يتكون من 6 أرقام.',
        ]);

        $rawPhone = trim((string) $request->phone);
        $code = trim((string) $request->code);

        $normalizedPhone = FamilyService::normalizePhone($rawPhone);
        if (! $normalizedPhone) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير صالح.',
            ], 422);
        }

        // 1. التحقق من صحة الرمز
        $otp = OtpCode::where('phone', $normalizedPhone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $otp || ! Hash::check($code, $otp->code_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
            ], 401);
        }

        // استهلاك الرمز
        $otp->forceFill(['consumed_at' => now()])->save();

        // 2. استرجاع بيانات التسجيل
        $cached = Cache::get('parent_reg_'.$normalizedPhone, []);
        $pseudo = trim((string) ($request->pseudo ?: ($cached['pseudo'] ?? 'parent_'.$normalizedPhone)));
        $email = trim((string) ($request->email ?: ($cached['email'] ?? '')));

        if (! $email) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات البريد الإلكتروني غير مكتملة، يرجى إعادة طلب الرمز.',
            ], 422);
        }

        // 3. التحقق من مطابقة التلاميذ للرقم
        $matchingStudents = Student::query()
            ->select('id', 'first_name', 'last_name', 'guardian_first_name', 'guardian_last_name', 'guardian_phone', 'mother_phone', 'guardian_email')
            ->where(function ($q) {
                $q->whereNotNull('guardian_phone')->orWhereNotNull('mother_phone');
            })
            ->get()
            ->filter(function (Student $st) use ($normalizedPhone) {
                return FamilyService::normalizePhone($st->guardian_phone) === $normalizedPhone
                    || FamilyService::normalizePhone($st->mother_phone) === $normalizedPhone;
            });

        if ($matchingStudents->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير مسجل لأي تلميذ في المدرسة.',
            ], 422);
        }

        // تحديث إيميل الولي في ملفات أبنائه إن لم يكن ممتلئاً
        foreach ($matchingStudents as $student) {
            if (empty($student->guardian_email)) {
                $student->update(['guardian_email' => $email]);
            }
        }

        // 4. إنشاء أو تحديث حساب المستخدم (User)
        $parentRole = Role::firstOrCreate(
            ['name' => 'parent'],
            ['display_name' => 'وليّ']
        );

        $user = User::where('phone', $normalizedPhone)->first()
            ?? User::where('email', $email)->first();

        $firstStudent = $matchingStudents->first();
        $guardianFirstName = $firstStudent->guardian_first_name ?: 'وليّ';
        $guardianLastName = $firstStudent->guardian_last_name ?: $pseudo;

        if ($user) {
            $user->update([
                'first_name' => $guardianFirstName,
                'last_name' => $guardianLastName,
                'username' => $pseudo,
                'email' => $email,
                'phone' => $normalizedPhone,
                'password' => Hash::make($normalizedPhone), // كلمة السر هي رقم الهاتف
                'phone_password' => $normalizedPhone,
                'role_id' => $parentRole->id,
                'is_active' => true,
            ]);
        } else {
            $user = User::create([
                'first_name' => $guardianFirstName,
                'last_name' => $guardianLastName,
                'username' => $pseudo,
                'email' => $email,
                'phone' => $normalizedPhone,
                'password' => Hash::make($normalizedPhone), // كلمة السر هي رقم الهاتف
                'phone_password' => $normalizedPhone,
                'role_id' => $parentRole->id,
                'is_active' => true,
            ]);
        }

        // مسح الكاش
        Cache::forget('parent_reg_'.$normalizedPhone);

        // 5. إصدار التوكن
        $effectivePermissions = $user->getEffectivePermissionNames();
        $token = $user->createToken('parent-pwa', $effectivePermissions)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تفعيل الحساب بنجاح! مرحباً بك في بوابة أولياء الأمور.',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => 'parent',
                ],
                'students_count' => $matchingStudents->count(),
            ],
        ], 200);
    }
}
