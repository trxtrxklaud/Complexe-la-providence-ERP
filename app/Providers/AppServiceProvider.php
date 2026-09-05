<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate Limiter عام للـ API:
        // مستخدم مسجل: 120 طلب/دقيقة (مرتبط بـ ID المستخدم)
        // زائر (غير مسجل): 60 طلب/دقيقة (مرتبط بـ IP)
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
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
