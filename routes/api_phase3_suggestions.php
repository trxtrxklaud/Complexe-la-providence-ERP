<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 3 API Route Suggestions
| These can be merged into routes/api.php
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Dashboard
    Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index']);

    // Students (improved listing)
    Route::get('/students', [App\Http\Controllers\StudentController::class, 'index']); // To be added

    // Enrollment (already exists)
    Route::post('/students/enroll', [App\Http\Controllers\StudentController::class, 'store']);

    // Future: Payments
    // Route::post('/payments', [App\Http\Controllers\PaymentController::class, 'store']);
});
