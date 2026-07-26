<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\FeeTypeController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\RosterController;

Route::middleware('throttle:5,1')->post('/login', [AuthController::class, 'login']);

// كل المسارات المصادَق عليها تمرّ بـ active فيمنع أي حساب معطَّل من الوصول.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user',    [AuthController::class, 'user']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // قائمة السنوات الدراسية — قراءة فقط تُستعمل في عدة شاشات، متاحة لأي مستخدِم مُفعَّل.
    Route::get('/academic-years', [AcademicYearController::class, 'index']);

    // الموظفون — صلاحية منفصلة
    Route::middleware('permission:manage_employees')->group(function () {
        Route::apiResource('/employees', EmployeeController::class);
    });

    // الرواتب — صلاحية منفصلة
    Route::middleware('permission:manage_salaries')->group(function () {
        Route::apiResource('/salaries', SalaryController::class);
    });

    // User Management
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::apiResource('/users', UserController::class);
    });

    // School structure — المستويات والأقسام
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/levels', [LevelController::class, 'index']);
        Route::post('/levels', [LevelController::class, 'store']);
        Route::put('/levels/{level}', [LevelController::class, 'update']);
        Route::delete('/levels/{level}', [LevelController::class, 'destroy']);

        Route::get('/sections', [SectionController::class, 'index']);
        Route::post('/sections', [SectionController::class, 'store']);
        Route::put('/sections/{section}', [SectionController::class, 'update']);
        Route::delete('/sections/{section}', [SectionController::class, 'destroy']);
    });

    // قوائم الأقسام — إدخال دفعي، تعديل، حذف، طباعة
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/rosters', [RosterController::class, 'index']);
        Route::post('/rosters/bulk', [RosterController::class, 'bulkStore']);
        Route::put('/rosters/{roster}', [RosterController::class, 'updateStudent']);
        Route::delete('/rosters/{roster}', [RosterController::class, 'destroy']);
    });

    // Students
    Route::middleware('permission:manage_students')->group(function () {
        // Compatibility route for New Student wizard (frontend posts here)
        Route::post('/students/enroll', [StudentController::class, 'store']);
        Route::apiResource('/students', StudentController::class);
        Route::post('/students/{student}/enroll',   [StudentController::class, 'enroll']);
        Route::post('/students/{student}/reenroll', [StudentController::class, 'reenroll']);
        Route::get('/students/{student}/balance',   [PaymentController::class, 'studentBalance']);
        Route::get('/students/{student}/fees',      [PaymentController::class, 'studentFees']);
    });

    // Payments
    Route::middleware('permission:manage_payments')->group(function () {
        Route::apiResource('/payments', PaymentController::class)->except(['update']);
        Route::apiResource('/fee-types', FeeTypeController::class);

        Route::get('/collection/years', [CollectionController::class, 'years']);
        Route::get('/collection/years/{year}/sections', [CollectionController::class, 'sectionsByYear']);
        Route::get('/collection/sections/{section}/students', [CollectionController::class, 'studentsBySection']);
        Route::post('/payments/collect', [CollectionController::class, 'collect']);
        Route::get('/enrollments/{enrollment}/ledger', [CollectionController::class, 'ledger']);
    });
});
