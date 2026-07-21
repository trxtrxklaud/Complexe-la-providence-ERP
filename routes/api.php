<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::middleware('throttle:5,1')->post('/login', [App\Http\Controllers\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/user',    [App\Http\Controllers\AuthController::class, 'user']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // User Management — admin only
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/roles', [App\Http\Controllers\UserController::class, 'roles']);
        Route::apiResource('/users', App\Http\Controllers\UserController::class);
    });

    // Students
    Route::get('/students', [App\Http\Controllers\StudentController::class, 'index']);
    Route::middleware('permission:enroll_student')->group(function () {
        Route::post('/students/enroll', [App\Http\Controllers\StudentController::class, 'store']);
    });
});
