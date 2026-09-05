<?php

use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\ParentController;
use App\Http\Controllers\Mobile\TeacherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات الجوال (Mobile) — طبقة إضافية معزولة تحت /api/mobile
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. يُحمَّل عبر سطر then: واحد في bootstrap/app.php دون
| المساس بـ routes/api.php. كل المسارات المصادَق عليها تمرّ بـ
| auth:sanctum + active (نفس حارس المنصّة)، ثم EnsureMobileRole لحصر
| الدور (parent | teacher)، ثم الصلاحيات الدقيقة عند اللزوم.
|
| النطاق على «أبنائي/قسمي» يُفرض داخل كل Controller عبر MobileScopeService
| — لا نثق بمعرّفات العميل.
|
*/

Route::prefix('mobile')->group(function () {

    // دخول الوليّ بالهاتف + OTP (بلا مصادقة مسبقة، محروس بـ throttle).
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/parent/request-otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('/parent/verify-otp', [MobileAuthController::class, 'verifyOtp']);
    });

    // المعلّم يدخل عبر /api/login القائم (لا تكرار هنا).

    Route::middleware(['auth:sanctum', 'active', 'throttle:120,1'])->group(function () {

        // ── الوليّ ─────────────────────────────────────────────
        Route::middleware(['mobile_role:parent', 'permission:view_own_children'])
            ->prefix('parent')
            ->group(function () {
                Route::get('/children', [ParentController::class, 'children']);
                Route::get('/children/{student}/ledger', [ParentController::class, 'ledger']);
                Route::get('/children/{student}/receipts', [ParentController::class, 'receipts']);
                Route::get('/announcements', [ParentController::class, 'announcements']);
            });

        // ── المعلّم ────────────────────────────────────────────
        Route::middleware(['mobile_role:teacher', 'permission:view_own_sections'])
            ->prefix('teacher')
            ->group(function () {
                Route::get('/sections', [TeacherController::class, 'sections']);
                Route::get('/sections/{section}/students', [TeacherController::class, 'students']);

                Route::middleware('permission:manage_attendance')
                    ->post('/sections/{section}/attendance', [TeacherController::class, 'storeAttendance']);

                Route::middleware('permission:manage_grades')
                    ->post('/sections/{section}/results', [TeacherController::class, 'storeResults']);

                Route::middleware('permission:manage_announcements')
                    ->post('/sections/{section}/announcements', [TeacherController::class, 'storeAnnouncement']);
            });
    });
});
