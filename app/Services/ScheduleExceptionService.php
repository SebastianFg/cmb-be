<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ExceptionType;
use App\Models\Appointment;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Ausencias y bloqueos sobre la agenda (RF-12, CU-05). Repite las reglas de
 * `Validation.exception` del prototipo: no se bloquea una fecha pasada, no se
 * pisan dos excepciones y la franja tiene que estar sin turnos activos.
 */
class ScheduleExceptionService
{
    private const CANCELLED = [
        AppointmentStatus::CancelledByProfessional->value,
        AppointmentStatus::CancelledByCenter->value,
    ];

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  array{professional_id: int, date: string, start_time: string, end_time: string, type: string, reason: string}  $values
     *
     * @throws ValidationException
     */
    public function declare(array $values): ScheduleException
    {
        $date = $this->availability->parseDate($values['date']);
        $starts = $this->at($date, $values['start_time']);
        $ends = $this->at($date, $values['end_time']);

        if ($ends->lessThanOrEqualTo($starts)) {
            throw $this->fail('end_time', 'La hora de fin tiene que ser posterior a la de inicio.');
        }

        if ($date->lessThan($this->availability->today())) {
            throw $this->fail('start_time', 'No se puede bloquear una fecha pasada.');
        }

        $this->assertNoOverlappingException($values['professional_id'], $starts, $ends);
        $this->assertRangeIsEmpty($values['professional_id'], $starts, $ends);

        return ScheduleException::query()->create([
            'professional_id' => $values['professional_id'],
            'starts_at' => $starts->utc(),
            'ends_at' => $ends->utc(),
            'type' => ExceptionType::from($values['type']),
            'reason' => trim($values['reason']),
        ]);
    }

    private function assertNoOverlappingException(int $professionalId, CarbonImmutable $starts, CarbonImmutable $ends): void
    {
        $exists = ScheduleException::query()
            ->where('professional_id', $professionalId)
            ->where('starts_at', '<', $ends->utc())
            ->where('ends_at', '>', $starts->utc())
            ->exists();

        if ($exists) {
            throw $this->fail(
                'start_time',
                'Ya hay una ausencia o un bloqueo que se superpone con ese horario.',
            );
        }
    }

    /**
     * En esta etapa la franja con turnos se rechaza: la reasignación en lote
     * de RF-19 todavía no existe.
     */
    private function assertRangeIsEmpty(int $professionalId, CarbonImmutable $starts, CarbonImmutable $ends): void
    {
        $timezone = $this->availability->timezone();

        $affected = Appointment::query()
            ->where('professional_id', $professionalId)
            ->where('starts_at', '<', $ends->utc())
            ->where('ends_at', '>', $starts->utc())
            ->whereNotIn('status', self::CANCELLED)
            ->orderBy('starts_at')
            ->get(['starts_at']);

        if ($affected->isEmpty()) {
            return;
        }

        $times = $affected
            ->map(fn (Appointment $item): string => $item->starts_at->setTimezone($timezone)->format('H:i'))
            ->implode(', ');

        throw $this->fail('start_time', sprintf(
            'Hay %s en esa franja (%s). Reprogramalos o cancelalos antes de bloquearla.',
            $affected->count() === 1 ? '1 turno' : "{$affected->count()} turnos",
            $times,
        ));
    }

    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = explode(':', $time);

        return $date->setTime((int) $hour, (int) $minute);
    }

    private function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
