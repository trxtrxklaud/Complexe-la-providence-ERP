<?php

namespace App\Providers;

use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\TwilioService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // مزوّد SMS واحد للتطبيق كله — يُبنى من الإعدادات لا من الحاوية
        // (معامِلاته primitive strings لا تُحلّ تلقائياً). يُبدَّل هنا فقط
        // عند تغيير المزوّد.
        $this->app->singleton(SmsProviderInterface::class, fn () => TwilioService::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate Limiters مخصصة حسب الدور:
        RateLimiter::for('api-admin', function (Request $request) {
            $user = $request->user();
            if ($user?->role?->name === 'parent') {
                return Limit::perMinute(30)->by($user->id);
            }
            if ($user?->role?->name === 'teacher') {
                return Limit::perMinute(60)->by($user->id);
            }

            return Limit::perMinute(120)->by($user?->id ?: $request->ip());
        });

        RateLimiter::for('api-teacher', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-parent', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Rate Limiter ديناميكي عام للـ API يعتمد على دور الحساب
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            if (! $user) {
                return Limit::perMinute(60)->by($request->ip());
            }

            $role = strtolower($user->role?->name ?? '');

            if ($role === 'parent') {
                return Limit::perMinute(30)->by($user->id);
            }

            if ($role === 'teacher') {
                return Limit::perMinute(60)->by($user->id);
            }

            return Limit::perMinute(120)->by($user->id);
        });

        // Rate Limiter خاص بتسجيل الدخول:
        // 5 محاولات في الدقيقة (مرتبط بـ IP + البريد الإلكتروني لمنع الـ brute-force)
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        // Rate Limiter خاص بالعمليات الحساسة (دفعات، رواتب، سحوبات، تسويات):
        // 30 طلب في الدقيقة (مرتبط بـ ID المستخدم أو الـ IP)
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
