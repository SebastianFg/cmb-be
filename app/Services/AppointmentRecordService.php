<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Lo que le pasa a un turno ya reservado: resultado y observación (RF-20 y
 * RF-21) y cancelación (RF-18). Las reglas repiten las de `js/agenda.js` y
 * `js/validation.js`, porque el navegador puede saltearse el formulario.
 */
class AppointmentRecordService
{
    public const NOTES_MAX = 500;

    /** Resultados que registra la agenda, en el orden del mockup. */
    public const ATTENDANCE = ['present', 'attended', 'no_show'];

    private const CLOSED = [AppointmentStatus::Attended, AppointmentStatus::NoShow];

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * «Presente» no tiene columna propia en el DER: el turno pasa a
     * `confirmed`, que es el estado del paciente que ya llegó.
     */
    public function attendanceOf(Appointment $appointment): ?string
    {
        return match ($appointment->status) {
            AppointmentStatus::Attended => 'attended',
            AppointmentStatus::NoShow => 'no_show',
            AppointmentStatus::Confirmed => 'present',
            default => null,
        };
    }

    /**
     * Registra resultado y observación. `$attendance` en `null` vuelve el
     * turno a reservado.
     *
     * @throws ValidationException
     */
    public function record(Appointment $appointment, ?string $attendance, ?string $notes): Appointment
    {
        $this->assertIsOpen($appointment);
        $this->assertAttendanceFits($appointment, $attendance);

        $appointment->fill([
            'notes' => filled($notes) ? trim($notes) : null,
            ...$this->changesFor($attendance),
        ])->save();

        return $appointment;
    }

    /**
     * Cancela el turno y libera la franja (RF-18). La cancelación fuera de la
     * ventana configurada queda marcada como penalizada, salvo que la haga el
     * profesional sobre su propia agenda: ahí el paciente no tuvo la culpa.
     *
     * @throws ValidationException
     */
    public function cancel(Appointment $appointment, User $actor, string $reason): Appointment
    {
        $this->assertIsOpen($appointment);

        $byProfessional = $appointment->professional_id === $actor->professional()->value('id');
        $isLate = $this->isLate($appointment);
        $penalized = $isLate && ! $byProfessional;

        $appointment->fill([
            'status' => $byProfessional
                ? AppointmentStatus::CancelledByProfessional
                : AppointmentStatus::CancelledByCenter,
            'cancelled_by' => $actor->id,
            'cancellation_reason' => trim($reason),
            'is_penalized' => $penalized,
            'attended_at' => null,
        ])->save();

        if ($penalized) {
            $this->penalize($appointment->patient);
        }

        return $appointment;
    }

    /** Si la cancelación llega con menos aviso que el configurado. */
    public function isLate(Appointment $appointment): bool
    {
        $window = Setting::query()->value('cancellation_window_minutes') ?? 0;

        return $this->availability->now()
            ->greaterThan($appointment->starts_at->subMinutes($window));
    }

    private function penalize(?Patient $patient): void
    {
        if (blank($patient)) {
            return;
        }

        $days = Setting::query()->value('cancellation_penalty_days') ?? 0;

        $patient->forceFill([
            'blocked_until' => $this->availability->today()->addDays($days),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function changesFor(?string $attendance): array
    {
        return match ($attendance) {
            'attended' => [
                'status' => AppointmentStatus::Attended,
                'attended_at' => $this->availability->now(),
            ],
            'no_show' => [
                'status' => AppointmentStatus::NoShow,
                'attended_at' => null,
            ],
            'present' => [
                'status' => AppointmentStatus::Confirmed,
                'attended_at' => null,
            ],
            default => [
                'status' => AppointmentStatus::Booked,
                'attended_at' => null,
            ],
        };
    }

    private function assertIsOpen(Appointment $appointment): void
    {
        if (in_array($appointment->status, [
            AppointmentStatus::CancelledByProfessional,
            AppointmentStatus::CancelledByCenter,
        ], true)) {
            throw ValidationException::withMessages([
                'appointment' => 'El turno ya está cancelado.',
            ]);
        }
    }

    /**
     * «Presente» se marca el día del turno; «atendido» y «ausente», recién
     * cuando el turno empezó.
     */
    private function assertAttendanceFits(Appointment $appointment, ?string $attendance): void
    {
        if (blank($attendance)) {
            return;
        }

        $timezone = $this->availability->timezone();
        $starts = $appointment->starts_at->setTimezone($timezone);

        if ($attendance === 'present') {
            if (! $starts->isSameDay($this->availability->today())) {
                throw ValidationException::withMessages([
                    'attendance' => 'La asistencia se registra el día del turno.',
                ]);
            }

            return;
        }

        if ($starts->greaterThan($this->availability->now())) {
            throw ValidationException::withMessages([
                'attendance' => 'El turno todavía no empezó: no se puede registrar el resultado.',
            ]);
        }
    }

    /** Si el turno ya quedó cerrado por resultado. */
    public function isClosed(Appointment $appointment): bool
    {
        return in_array($appointment->status, self::CLOSED, true);
    }
}
