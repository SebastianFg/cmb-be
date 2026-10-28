<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function (): JsonResponse {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('users/login', [AuthController::class, 'loginUser'])
            ->middleware('throttle:login-users')
            ->name('users.login');

        Route::post('patients/login', [AuthController::class, 'loginPatient'])
            ->middleware('throttle:login-patients')
            ->name('patients.login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        });
    });
});
