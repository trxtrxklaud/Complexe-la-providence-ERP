<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\OtpRequiredException;
use App\Http\Controllers\Controller;
use App\Services\Mobile\PhoneAuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneLoginController extends Controller
{
    public function __construct(
        private PhoneAuthService $authService
    ) {}

    /**
     * تسجيل الدخول الموحد برقم الهاتف.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:8|max:20',
            // رمز التحقق — إلزامي فعلياً لدور الوليّ (يُفحص في الخدمة).
            'otp_code' => 'nullable|string|size:6',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'phone.min' => 'رقم الهاتف غير صالح.',
            'otp_code.size' => 'رمز التحقق يجب أن يكون 6 أرقام.',
        ]);

        try {
            $result = $this->authService->loginByPhone($request->phone, $request->input('otp_code'));

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح.',
                'data' => [
                    'access_token' => $result['access_token'],
                    'token_type' => $result['token_type'],
                    'role' => $result['role'],
                    'user' => $result['user'],
                    'profile' => $result['profile'],
                ],
            ], 200);

        } catch (OtpRequiredException $e) {
            return response()->json([
                'success' => false,
                'otp_required' => true,
                'message' => $e->getMessage(),
                'data' => null,
            ], 401);
        } catch (AuthenticationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 401);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ غير متوقع. يرجى المحاولة لاحقاً.',
                'data' => null,
            ], 500);
        }
    }
}
