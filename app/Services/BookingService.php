<?php

namespace App\Services;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserva de turnos (RF-15, RNF-05, RNF-06). Repite del lado del servidor las
 * reglas de `js/validation.js`, con los mismos mensajes, porque el navegador
 * puede saltearse las validaciones del formulario.
 *
 * El alta corre dentro de una transacción: entre que se listan las franjas y
 * se guarda el turno otra sesión pudo tomar la misma, y las restricciones de
 * exclusión de Postgres son la última palabra.
 */
class BookingService
{
    private const REASON_MAX = 200;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  array{patient_id?: int, specialty_id: int, professional_id: int, date: string, start_time: string, reason?: string|null}  $values
     *
     * @throws ValidationException
     */
    public function book(array $values, Patient $patient, User|Patient $actor): Appointment
    {
        $selfService = $actor instanceof Patient;

        $this->assertPatientCanBook($patient, $selfService);
        $date = $this->assertDateIsBookable($values['date']);
        $slot = $this->assertSlotIsFree($date, $values);
        $this->assertReasonFits($values['reason'] ?? null);
        $this->assertPatientIsFree($patient, $slot, $selfService);

        return DB::transaction(fn (): Appointment => Appointment::query()->create([
            'patient_id' => $patient->id,
            'professional_id' => $slot['professional_id'],
            'specialty_id' => $slot['specialty_id'],
            'room_id' => $slot['room_id'],
            'created_by' => $selfService ? null : $actor->id,
            // Los casts de Eloquent guardan la hora sin el offset, así que las
            // franjas se pasan a UTC: si no, Postgres las lee en su propio
            // huso y el turno queda corrido tres horas.
            'starts_at' => $slot['starts_at']->utc(),
            'ends_at' => $slot['ends_at']->utc(),
            'status' => AppointmentStatus::Booked,
            'source' => $selfService ? AppointmentSource::Portal : AppointmentSource::Desk,
            'confirmation_code' => $this->newConfirmationCode(),
            'reason' => filled($values['reason'] ?? null) ? trim((string) $values['reason']) : null,
        ]));
    }

    /**
     * Reprograma un turno (RF-18): conserva el paciente y el código de
     * verificación, y libera la franja anterior. Las reglas son las mismas
     * del alta, salvo que el propio turno no cuenta como ocupante.
     *
     * @param  array{specialty_id: int, professional_id: int, date: string, start_time: string, reason?: string|null}  $values
     *
     * @throws ValidationException
     */
    public function reschedule(Appointment $appointment, array $values, User $actor): Appointment
    {
        $this->assertCanBeMoved($appointment);

        $patient = $appointment->patient;
        $date = $this->assertDateIsBookable($values['date']);
        $slot = $this->assertSlotIsFree($date, $values, $appointment->id);
        $this->assertReasonFits($values['reason'] ?? null);
        $this->assertPatientIsFree($patient, $slot, false, $appointment->id);

        return DB::transaction(function () use ($appointment, $slot, $values, $actor): Appointment {
            $appointment->fill([
                'professional_id' => $slot['professional_id'],
                'specialty_id' => $slot['specialty_id'],
                'room_id' => $slot['room_id'],
                'created_by' => $actor->id,
                'starts_at' => $slot['starts_at']->utc(),
                'ends_at' => $slot['ends_at']->utc(),
                // Vuelve a quedar sin confirmar: el paciente todavía no sabe
                // del cambio.
                'status' => AppointmentStatus::Booked,
                'attended_at' => null,
                'reason' => filled($values['reason'] ?? null)
                    ? trim((string) $values['reason'])
                    : $appointment->reason,
            ])->save();

            return $appointment;
        });
    }

    private function assertCanBeMoved(Appointment $appointment): void
    {
        $closed = [
            AppointmentStatus::CancelledByProfessional,
            AppointmentStatus::CancelledByCenter,
            AppointmentStatus::Attended,
            AppointmentStatus::NoShow,
        ];

        if (in_array($appointment->status, $closed, true)) {
            throw $this->fail('appointment', 'El turno ya está cerrado: no se puede reprogramar.');
        }
    }

    public function isPenalized(Patient $patient): bool
    {
        return filled($patient->blocked_until)
            && $patient->blocked_until->greaterThanOrEqualTo($this->availability->today());
    }

    /** La autogestión queda bloqueada por una penalización; el mostrador no. */
    private function assertPatientCanBook(Patient $patient, bool $selfService): void
    {
        if ($selfService && $this->isPenalized($patient)) {
            throw $this->fail('patient_id', sprintf(
                'Tu cuenta tiene una penalización hasta el %s por una cancelación fuera de término. '.
                'Mientras tanto, podés sacar turno llamando al centro.',
                $this->shortDate($patient->blocked_until),
            ));
        }
    }

    private function assertDateIsBookable(string $value): CarbonImmutable
    {
        $date = $this->availability->parseDate($value);
        $today = $this->availability->today();
        $horizon = $this->availability->horizonDays();

        if ($date->lessThan($today)) {
            throw $this->fail('date', 'No se puede sacar un turno en una fecha pasada.');
        }

        if ((int) $today->diffInDays($date) > $horizon) {
            throw $this->fail('date', "Solo se pueden reservar turnos dentro de los próximos {$horizon} días.");
        }

        $holiday = $this->availability->holidayOn($date);

        if ($holiday) {
            throw $this->fail('date', sprintf(
                'El %s el centro no atiende (%s).',
                $this->shortDate($date),
                $holiday->name,
            ));
        }

        return $date;
    }

    /**
     * @param  array{specialty_id: int, professional_id: int, start_time: string}  $values
     * @return array<string, mixed>
     */
    private function assertSlotIsFree(CarbonImmutable $date, array $values, ?int $ignoreAppointmentId = null): array
    {
        $slots = $this->availability->bookableSlots(
            $date,
            (int) $values['specialty_id'],
            (int) $values['professional_id'],
            $ignoreAppointmentId,
        );

        $slot = collect($slots)->first(
            fn (array $item): bool => $item['start_time'] === $values['start_time'],
        );

        if (blank($slot)) {
            throw $this->fail('slot', "La franja de las {$values['start_time']} ya no está disponible. Elegí otra.");
        }

        return $slot;
    }

    private function assertReasonFits(?string $reason): void
    {
        if (filled($reason) && mb_strlen($reason) > self::REASON_MAX) {
            throw $this->fail('reason', 'El motivo admite hasta '.self::REASON_MAX.' caracteres.');
        }
    }

    /**
     * RNF-06: un paciente no puede tener dos turnos superpuestos, ni siquiera
     * con profesionales distintos.
     *
     * @param  array<string, mixed>  $slot
     */
    private function assertPatientIsFree(Patient $patient, array $slot, bool $selfService, ?int $ignoreAppointmentId = null): void
    {
        $conflict = Appointment::query()
            ->where('patient_id', $patient->id)
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->where('starts_at', '<', $slot['ends_at']->utc())
            ->where('ends_at', '>', $slot['starts_at']->utc())
            ->whereNotIn('status', [
                AppointmentStatus::CancelledByProfessional->value,
                AppointmentStatus::CancelledByCenter->value,
            ])
            ->with(['professional.user:id,name', 'specialty:id,name'])
            ->first();

        if (blank($conflict)) {
            return;
        }

        $timezone = $this->availability->timezone();
        $starts = $conflict->starts_at->setTimezone($timezone);
        $ends = $conflict->ends_at->setTimezone($timezone);

        throw $this->fail('slot', sprintf(
            '%s un turno el %s de %s a %s con %s (%s). '.
            'Un paciente no puede tener dos turnos superpuestos: elegí otra franja.',
            $selfService ? 'Ya tenés' : 'El paciente ya tiene',
            $this->shortDate($starts),
            $starts->format('H:i'),
            $ends->format('H:i'),
            $conflict->professional?->user?->name ?? 'otro profesional',
            $conflict->specialty?->name ?? 'otra especialidad',
        ));
    }

    /** Código de verificación del comprobante, sin caracteres ambiguos. */
    private function newConfirmationCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $code = 'TRN-';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Appointment::query()->where('confirmation_code', $code)->exists());

        return $code;
    }

    /** Igual que `Dates.formatShort` del prototipo: 07/09/2026. */
    private function shortDate(CarbonInterface $date): string
    {
        return $date->format('d/m/Y');
    }

    private function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
