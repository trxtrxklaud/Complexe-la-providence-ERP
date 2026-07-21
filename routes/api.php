<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\PaymentController;

Route::middleware('throttle:5,1')->post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user',    [AuthController::class, 'user']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // User Management
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::apiResource('/users', UserController::class);
    });

    // Students
    Route::middleware('permission:manage_students')->group(function () {
        Route::apiResource('/students', StudentController::class);
        Route::post('/students/{student}/enroll',   [StudentController::class, 'enroll']);
        Route::post('/students/{student}/reenroll', [StudentController::class, 'reenroll']);
        Route::get('/students/{student}/balance',   [PaymentController::class, 'studentBalance']);
        Route::get('/students/{student}/fees',      [PaymentController::class, 'studentFees']);
    });

    // Payments
    Route::middleware('permission:manage_payments')->group(function () {
        Route::apiResource('/payments', PaymentController::class)->except(['update']);
    });
});
