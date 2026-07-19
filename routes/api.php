<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\CheckPermission;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/user', [App\Http\Controllers\AuthController::class, 'user']);
    
    Route::get('/dashboard', [DashboardController::class, 'index']);
    
    // User Management Routes
    Route::get('/roles', [App\Http\Controllers\UserController::class, 'roles']);
    Route::apiResource('/users', App\Http\Controllers\UserController::class);

    // Students Routes
    Route::get('/students', [App\Http\Controllers\StudentController::class, 'index']);
    Route::post('/students/enroll', [App\Http\Controllers\StudentController::class, 'store']);

    // Future payment routes can be added here
});
