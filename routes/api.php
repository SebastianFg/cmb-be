<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthInsuranceController;
use App\Http\Controllers\NationalityController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\ScheduleExceptionController;
use App\Http\Controllers\SpecialtyController;
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

    // Catálogos: los lee cualquier sesión, porque el portal también arma
    // formularios con ellos.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('nationalities', [NationalityController::class, 'index'])->name('nationalities.index');
        Route::get('health-insurances', [HealthInsuranceController::class, 'index'])->name('health-insurances.index');
        Route::get('specialties', [SpecialtyController::class, 'index'])->name('specialties.index');
        Route::get('professionals', [ProfessionalController::class, 'index'])->name('professionals.index');
    });

    // Disponibilidad: la consultan el mostrador y el portal con los mismos
    // parámetros, porque el cálculo es el mismo.
    Route::middleware('auth:sanctum')->prefix('availability')->name('availability.')->group(function (): void {
        Route::get('days', [AvailabilityController::class, 'days'])->name('days');
        Route::get('slots', [AvailabilityController::class, 'slots'])->name('slots');
    });

    // Autogestión del paciente (CU-02).
    Route::middleware(['auth:sanctum', 'ability:patient'])->prefix('portal')->name('appointments.portal.')->group(function (): void {
        Route::get('appointments', [AppointmentController::class, 'mine'])->name('index');
        Route::post('appointments', [AppointmentController::class, 'storeForPatient'])->name('store');
    });

    // Panel principal: el profesional ve sólo su agenda.
    Route::middleware(['auth:sanctum', 'role:admin,reception,professional'])->group(function (): void {
        Route::get('reports/dashboard-summary', [DashboardController::class, 'show'])
            ->name('reports.dashboard-summary');
    });

    // Fichas de pacientes: mostrador y administración.
    Route::middleware(['auth:sanctum', 'role:admin,reception'])->group(function (): void {
        Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
        Route::post('patients', [PatientController::class, 'store'])->name('patients.store');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    });

    // Agenda del centro: el profesional ve y toca sólo la suya.
    Route::middleware(['auth:sanctum', 'role:admin,reception,professional'])->group(function (): void {
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('agenda', [AgendaController::class, 'show'])->name('agenda.show');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::patch('appointments/{appointment}', [AppointmentController::class, 'record'])->name('appointments.record');
        Route::put('appointments/{appointment}', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('schedule-exceptions', [ScheduleExceptionController::class, 'store'])->name('schedule-exceptions.store');
    });
});
