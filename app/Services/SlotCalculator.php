<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cálculo de disponibilidad (RF-13). Expande la agenda semanal de un
 * profesional en franjas de un día y descuenta feriados, ausencias, bloqueos
 * y turnos ya tomados.
 *
 * No consulta la base: recibe las colecciones ya cargadas, para que el panel
 * pueda recorrer dos meses sin una consulta por día.
 */
class SlotCalculator
{
    /**
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, ScheduleException>  $exceptions
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, Holiday>  $holidays
     */
    public function __construct(
        private readonly Collection $schedules,
        private readonly Collection $exceptions,
        private readonly Collection $appointments,
        private readonly Collection $holidays,
        private readonly string $timezone,
    ) {}

    public function isHoliday(CarbonImmutable $date): bool
    {
        return $this->holidays->contains(
            fn (Holiday $holiday): bool => $holiday->date->isSameDay($date),
        );
    }

    /**
     * Franjas publicadas de un profesional en un día: las que su agenda genera
     * descontando feriados y las horas bloqueadas. No descuenta los turnos,
     * porque es el denominador de la ocupación.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function publishedSlots(int $professionalId, CarbonImmutable $date): array
    {
        if ($this->isHoliday($date)) {
            return [];
        }

        $slots = [];

        foreach ($this->schedulesFor($professionalId, $date) as $schedule) {
            $step = $schedule->slot_duration_minutes;
            $cursor = $this->at($date, $schedule->start_time);
            $last = $this->at($date, $schedule->end_time);

            while ($cursor->addMinutes($step)->lessThanOrEqualTo($last)) {
                $ends = $cursor->addMinutes($step);

                if (! $this->isBlocked($professionalId, $cursor, $ends)) {
                    $slots[] = ['starts_at' => $cursor, 'ends_at' => $ends];
                }

                $cursor = $ends;
            }
        }

        return $slots;
    }

    public function countPublishedSlots(int $professionalId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $total = 0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $total += count($this->publishedSlots($professionalId, $day));
        }

        return $total;
    }

    /** Próximo día de atención: saltea domingos y feriados. */
    public function nextWorkingDay(CarbonImmutable $from): CarbonImmutable
    {
        $day = $from->addDay();

        while ($day->dayOfWeek === 0 || $this->isHoliday($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function schedulesFor(int $professionalId, CarbonImmutable $date): Collection
    {
        return $this->schedules->filter(
            fn (Schedule $schedule): bool => $schedule->professional_id === $professionalId
                && (int) $schedule->weekday === $date->dayOfWeek
                && $schedule->valid_from->lessThanOrEqualTo($date)
                && (blank($schedule->valid_until) || $schedule->valid_until->greaterThanOrEqualTo($date)),
        );
    }

    private function isBlocked(int $professionalId, CarbonImmutable $starts, CarbonImmutable $ends): bool
    {
        return $this->exceptions->contains(
            fn (ScheduleException $exception): bool => $exception->professional_id === $professionalId
                && $exception->starts_at->lessThan($ends)
                && $exception->ends_at->greaterThan($starts),
        );
    }

    private function at(CarbonImmutable $date, mixed $time): CarbonImmutable
    {
        [$hour, $minute] = explode(':', substr((string) $time, 0, 5));

        return $date->setTime((int) $hour, (int) $minute)->setTimezone($this->timezone);
    }
}
