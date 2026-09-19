<?php

use App\Http\Controllers\Mobile\AdminController;
use App\Http\Controllers\Mobile\AdminNotificationController;
use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\ParentContentController;
use App\Http\Controllers\Mobile\ParentController;
use App\Http\Controllers\Mobile\PhoneLoginController;
use App\Http\Controllers\Mobile\TeacherContentController;
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
        Route::middleware(['mobile_role:parent', 'permission:view_own_children', 'throttle:api-parent'])
            ->prefix('parent')
            ->group(function () {
                Route::get('/children', [ParentController::class, 'children']);
                Route::get('/children/{student}', [ParentController::class, 'show']);
                Route::get('/children/{student}/ledger', [ParentController::class, 'ledger']);
                Route::get('/children/{student}/receipts', [ParentController::class, 'receipts']);
                Route::get('/children/{student}/attendance', [ParentController::class, 'attendance']);
                Route::get('/children/{student}/grades', [ParentController::class, 'grades']);
                Route::get('/children/{student}/timetable', [ParentController::class, 'timetable']);
                Route::get('/children/{student}/exams', [ParentController::class, 'exams']);
                Route::get('/children/{student}/clubs', [ParentController::class, 'clubs']);
                Route::get('/announcements', [ParentController::class, 'announcements']);
                Route::get('/notifications', [ParentController::class, 'notifications']);
                Route::get('/children/{student}/homework', [ParentContentController::class, 'homework']);
                Route::get('/children/{student}/behavior', [ParentContentController::class, 'behavior']);
            });

        // ── المعلّم ────────────────────────────────────────────
        Route::middleware(['mobile_role:teacher', 'permission:view_own_sections', 'throttle:api-teacher'])
            ->prefix('teacher')
            ->group(function () {
                Route::get('/sections', [TeacherController::class, 'sections']);
                Route::get('/sections/{section}/students', [TeacherController::class, 'students']);

                Route::middleware('permission:manage_attendance')->group(function () {
                    Route::get('/sections/{section}/attendance', [TeacherController::class, 'getAttendance']);
                    Route::post('/sections/{section}/attendance', [TeacherController::class, 'storeAttendance']);
                });

                Route::middleware('permission:manage_grades')->group(function () {
                    Route::get('/sections/{section}/results', [TeacherController::class, 'getResults']);
                    Route::post('/sections/{section}/results', [TeacherController::class, 'storeResults']);
                    Route::get('/sections/{section}/grades', [TeacherController::class, 'getResults']);
                    Route::post('/sections/{section}/grades', [TeacherController::class, 'storeResults']);
                });

                Route::middleware('permission:manage_announcements')
                    ->post('/sections/{section}/announcements', [TeacherController::class, 'storeAnnouncement']);

                Route::middleware('permission:manage_homework')->group(function () {
                    Route::get('/sections/{section}/homework', [TeacherContentController::class, 'indexHomework']);
                    Route::post('/sections/{section}/homework', [TeacherContentController::class, 'storeHomework']);
                });

                Route::middleware('permission:manage_behavior')->group(function () {
                    Route::get('/sections/{section}/behavior', [TeacherContentController::class, 'indexBehavior']);
                    Route::post('/sections/{section}/behavior', [TeacherContentController::class, 'storeBehavior']);
                });
            });

        // ── الإدارة ────────────────────────────────────────────
        Route::middleware('mobile_role:admin')->prefix('admin')->group(function () {

            // إشعار عام (إنشاء)
            Route::post('/notifications/send', [AdminNotificationController::class, 'store']);

            // إحصاءات
            Route::get('/stats', [AdminController::class, 'stats']);

            // المستويات
            Route::get('/levels', [AdminController::class, 'levels']);

            // الأقسام (CRUD)
            Route::get('/sections',              [AdminController::class, 'sections']);
            Route::post('/sections',             [AdminController::class, 'createSection']);
            Route::get('/sections/{section}',    [AdminController::class, 'section']);
            Route::put('/sections/{section}',    [AdminController::class, 'updateSection']);
            Route::delete('/sections/{section}', [AdminController::class, 'deleteSection']);

            // جدول حصص قسم محدد
            Route::get('/sections/{section}/timetable', [AdminController::class, 'sectionTimetable']);

            // العائلات
            Route::get('/families',       [AdminController::class, 'families']);
            Route::get('/families/show',  [AdminController::class, 'family']); // ?phone=XXXX

            // التلاميذ
            Route::get('/students',                           [AdminController::class, 'students']);
            Route::get('/students/{student}',                 [AdminController::class, 'student']);
            Route::get('/students/{student}/attendance',      [AdminController::class, 'studentAttendance']);
            Route::get('/students/{student}/grades',          [AdminController::class, 'studentGrades']);
            Route::get('/students/{student}/ledger',          [AdminController::class, 'studentLedger']);

            // المعلمون
            Route::get('/teachers',              [AdminController::class, 'teachers']);
            Route::get('/teachers/{employee}',   [AdminController::class, 'teacher']);

            // الحضور (عرض شامل)
            Route::get('/attendance', [AdminController::class, 'attendance']);

            // الدرجات (عرض شامل)
            Route::get('/grades', [AdminController::class, 'grades']);

            // الكشف المالي (عرض شامل)
            Route::get('/ledgers', [AdminController::class, 'ledgers']);

            // النوادي
            Route::get('/clubs',                  [AdminController::class, 'clubs']);
            Route::get('/clubs/{club}',            [AdminController::class, 'club']);
            Route::get('/club-subscriptions',      [AdminController::class, 'clubSubscriptions']);

            // الإشعارات / الإعلانات (قراءة)
            Route::get('/notifications', [AdminController::class, 'notifications']);

            // الجدول الزمني العام
            Route::get('/timetable', [AdminController::class, 'timetable']);
        });
    });
});

// Phone-only login (unified for admin/teacher/parent)
Route::post('/auth/login-by-phone', [PhoneLoginController::class, 'login'])
    ->middleware('throttle:10,1');

// OTP request alias (supports GET and POST)
Route::match(['GET', 'POST'], '/auth/request-otp', [MobileAuthController::class, 'requestOtp'])
    ->middleware('throttle:10,1');

