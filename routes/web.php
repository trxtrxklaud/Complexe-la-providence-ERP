<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Health check endpoint for uptime monitors and self-healing infrastructure
Route::get('/health', function () {
    try {
        DB::select('SELECT 1');
        $dbStatus = 'ok';
        $status = 'ok';
        $httpCode = 200;
    } catch (\Throwable $e) {
        $dbStatus = 'error';
        $status = 'error';
        $httpCode = 503;
    }

    return response()->json([
        'status' => $status,
        'db' => $dbStatus,
        'timestamp' => now()->toIso8601String(),
    ], $httpCode);
});

// Serve the React SPA for all non-API routes
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
