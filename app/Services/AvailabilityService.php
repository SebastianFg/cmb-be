<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Disponibilidad para reservar (RF-12). Expande la agenda semanal en franjas
 * y descuenta feriados, ausencias, bloqueos y turnos ya tomados.
 *
 * Es el equivalente de `js/availability.js` del prototipo: `daySlots`
 * devuelve el día completo con el estado de cada franja, porque el mostrador
 * muestra también las ocupadas, y `bookableSlots` deja sólo las reservables.
 *
 * Los datos de todo el rango se cargan en una tanda y el cálculo se hace en
 * memoria, para que la tira de dos semanas no cueste una consulta por día.
 *
 * @phpstan-type Slot array{professional_id: int, professional: string, license_number: string|null, specialty_id: int, specialty: string|null, room_id: int|null, room: string|null, date: string, start_time: string, end_time: string, duration_minutes: int, state: string, appointment: Appointment|null, exception: ScheduleException|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable}
 */
class AvailabilityService
{
    /** Días que muestran la tira del portal y el selector del mostrador. */
    public const DAYS_SHOWN = 14;

    /** Horizonte de reserva de fábrica, si `settings` no lo define. */
    public const DEFAULT_HORIZON_DAYS = 30;

    private const CANCELLED = [
        AppointmentStatus::CancelledByProfessional->value,
        AppointmentStatus::CancelledByCenter->value,
    ];

    public function timezone(): string
    {
        return Setting::query()->value('timezone') ?? config('app.timezone');
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function horizonDays(): int
    {
        return Setting::query()->value('booking_horizon_days') ?? self::DEFAULT_HORIZON_DAYS;
    }

    /** Una fecha `Y-m-d` leída en el huso del centro. */
    public function parseDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone())->startOfDay();
    }

    public function holidayOn(CarbonImmutable $date): ?Holiday
    {
        return Holiday::query()->whereDate('date', $date->toDateString())->first();
    }

    /**
     * Franjas de un día con su estado: `free`, `taken` o `blocked`. Sin
     * profesional elegido devuelve las de todos los que atienden la
     * especialidad, ordenadas por hora.
     *
     * @return list<Slot>
     */
    public function daySlots(CarbonImmutable $date, int $specialtyId, ?int $professionalId = null, ?int $ignoreAppointmentId = null): array
    {
        return $this->slotsByDate($date, $date, $specialtyId, $professionalId, $ignoreAppointmentId)[$date->toDateString()] ?? [];
    }

    /**
     * Franjas del día de la agenda del centro (RF-26): todas las
     * especialidades, porque la grilla es por profesional y no por motivo de
     * consulta. Sin profesional devuelve las de todos los que atienden.
     *
     * @return list<Slot>
     */
    public function agendaSlots(CarbonImmutable $date, ?int $professionalId = null): array
    {
        return $this->slotsByDate($date, $date, null, $professionalId)[$date->toDateString()] ?? [];
    }

    /**
     * Franjas reservables: libres y que todavía no empezaron. Una franja de
     * hoy a las 09:00 ya no se ofrece a las 09:05.
     *
     * `$ignoreAppointmentId` deja afuera un turno: al reprogramarlo, su
     * propia franja tiene que volver a contar como libre.
     *
     * @return list<Slot>
     */
    public function bookableSlots(CarbonImmutable $date, int $specialtyId, ?int $professionalId = null, ?int $ignoreAppointmentId = null): array
    {
        return $this->onlyBookable(
            $this->daySlots($date, $specialtyId, $professionalId, $ignoreAppointmentId),
            $this->now(),
        );
    }

    /**
     * Próximos días sin domingos, con el feriado y la cantidad de franjas
     * libres de cada uno: es lo que alimenta la tira del portal y el
     * selector de fecha del mostrador.
     *
     * @return list<array{date: string, weekday: int, holiday: string|null, free: int}>
     */
    public function upcomingDays(int $specialtyId, ?int $professionalId = null, ?int $count = null): array
    {
        $wanted = $count ?? self::DAYS_SHOWN;
        $dates = $this->workingDates($wanted);

        if ($dates === []) {
            return [];
        }

        $last = $dates[count($dates) - 1];
        $byDate = $this->slotsByDate($dates[0], $last, $specialtyId, $professionalId);
        $holidays = $this->holidaysBetween($dates[0], $last);
        $horizon = $this->today()->addDays($this->horizonDays());
        $now = $this->now();

        return array_map(function (CarbonImmutable $date) use ($byDate, $holidays, $horizon, $now): array {
            $iso = $date->toDateString();

            return [
                'date' => $iso,
                'weekday' => $date->dayOfWeek,
                'holiday' => $holidays->get($iso)?->name,
                'free' => $date->greaterThan($horizon)
                    ? 0
                    : count($this->onlyBookable($byDate[$iso] ?? [], $now)),
            ];
        }, $dates);
    }

    /**
     * Franjas de cada día del rango, indexadas por fecha. Carga los horarios,
     * las excepciones, los turnos y los feriados del rango en cuatro
     * consultas y después calcula en memoria.
     *
     * @return array<string, list<Slot>>
     */
    private function slotsByDate(CarbonImmutable $from, CarbonImmutable $to, ?int $specialtyId, ?int $professionalId, ?int $ignoreAppointmentId = null): array
    {
        $schedules = $this->schedulesFor($specialtyId, $professionalId);

        if ($schedules->isEmpty()) {
            return [];
        }

        $professionalIds = $schedules->pluck('professional_id')->unique()->values()->all();
        $exceptions = $this->exceptionsBetween($professionalIds, $from, $to);
        $taken = $this->appointmentsBetween($professionalIds, $from, $to, $ignoreAppointmentId);
        $holidays = $this->holidaysBetween($from, $to);
        $byDate = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $iso = $date->toDateString();

            if ($holidays->has($iso)) {
                $byDate[$iso] = [];

                continue;
            }

            $byDate[$iso] = $this->slotsOn($date, $schedules, $exceptions, $taken);
        }

        return $byDate;
    }

    /**
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, ScheduleException>  $exceptions
     * @param  Collection<int, Appointment>  $taken
     * @return list<Slot>
     */
    private function slotsOn(CarbonImmutable $date, Collection $schedules, Collection $exceptions, Collection $taken): array
    {
        $slots = [];

        foreach ($schedules->filter(fn (Schedule $schedule): bool => $this->appliesOn($schedule, $date)) as $schedule) {
            $step = $schedule->slot_duration_minutes;
            $last = $this->at($date, $schedule->end_time);

            for ($starts = $this->at($date, $schedule->start_time); $starts->addMinutes($step)->lessThanOrEqualTo($last); $starts = $starts->addMinutes($step)) {
                $ends = $starts->addMinutes($step);
                $appointment = $this->overlapping($taken, $schedule->professional_id, $starts, $ends);
                $exception = $this->overlapping($exceptions, $schedule->professional_id, $starts, $ends);

                $slots[] = [
                    'professional_id' => $schedule->professional_id,
                    'professional' => $schedule->professional?->user?->name ?? '',
                    'license_number' => $schedule->professional?->license_number,
                    'specialty_id' => $schedule->specialty_id,
                    'specialty' => $schedule->specialty?->name,
                    'room_id' => $schedule->room_id,
                    'room' => $schedule->room?->name,
                    'date' => $date->toDateString(),
                    'start_time' => $starts->format('H:i'),
                    'end_time' => $ends->format('H:i'),
                    'duration_minutes' => $step,
                    'state' => match (true) {
                        filled($appointment) => 'taken',
                        filled($exception) => 'blocked',
                        default => 'free',
                    },
                    // La agenda muestra el turno y el motivo del bloqueo; la
                    // reserva sólo mira el estado.
                    'appointment' => $appointment,
                    'exception' => $exception,
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                ];
            }
        }

        usort($slots, fn (array $a, array $b): int => [$a['start_time'], $a['professional']] <=> [$b['start_time'], $b['professional']]);

        return $slots;
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    private function onlyBookable(array $slots, CarbonImmutable $now): array
    {
        return array_values(array_filter(
            $slots,
            fn (array $slot): bool => $slot['state'] === 'free' && $slot['starts_at']->greaterThan($now),
        ));
    }

    /**
     * Los próximos días de atención, sin domingos.
     *
     * @return list<CarbonImmutable>
     */
    private function workingDates(int $wanted): array
    {
        $dates = [];

        for ($date = $this->today(); count($dates) < $wanted; $date = $date->addDay()) {
            if ($date->dayOfWeek !== CarbonImmutable::SUNDAY) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** @return Collection<string, Holiday> */
    private function holidaysBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Holiday::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Holiday $holiday): string => $holiday->date->toDateString());
    }

    /** @return Collection<int, Schedule> */
    private function schedulesFor(?int $specialtyId, ?int $professionalId): Collection
    {
        return Schedule::query()
            ->with(['professional.user:id,name', 'room:id,name', 'specialty:id,name'])
            ->when($specialtyId, fn ($query) => $query->where('specialty_id', $specialtyId))
            ->when($professionalId, fn ($query) => $query->where('professional_id', $professionalId))
            ->get();
    }

    private function appliesOn(Schedule $schedule, CarbonImmutable $date): bool
    {
        return $schedule->weekday === $date->dayOfWeek
            && $schedule->valid_from->lessThanOrEqualTo($date)
            && (blank($schedule->valid_until) || $schedule->valid_until->greaterThanOrEqualTo($date));
    }

    /**
     * Los límites van en UTC: Eloquent liga las fechas sin el offset, así que
     * una hora local se leería en el huso del servidor de base.
     *
     * @param  list<int>  $professionalIds
     * @return Collection<int, ScheduleException>
     */
    private function exceptionsBetween(array $professionalIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ScheduleException::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('starts_at', '<', $to->endOfDay()->utc())
            ->where('ends_at', '>', $from->startOfDay()->utc())
            ->get();
    }

    /**
     * @param  list<int>  $professionalIds
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(array $professionalIds, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointmentId = null): Collection
    {
        return Appointment::query()
            ->with([
                'patient:id,first_name,last_name,document_number,health_insurance_id',
                'patient.healthInsurance:id,name,plan',
                'room:id,name',
            ])
            ->whereIn('professional_id', $professionalIds)
            ->where('starts_at', '<', $to->endOfDay()->utc())
            ->where('ends_at', '>', $from->startOfDay()->utc())
            ->whereNotIn('status', self::CANCELLED)
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->get();
    }

    /**
     * @template TModel of Appointment|ScheduleException
     *
     * @param  Collection<int, TModel>  $items
     * @return TModel|null
     */
    private function overlapping(Collection $items, int $professionalId, CarbonImmutable $starts, CarbonImmutable $ends)
    {
        return $items->first(
            fn ($item): bool => $item->professional_id === $professionalId
                && $item->starts_at->lessThan($ends)
                && $item->ends_at->greaterThan($starts),
        );
    }

    private function at(CarbonImmutable $date, mixed $time): CarbonImmutable
    {
        [$hour, $minute] = explode(':', substr((string) $time, 0, 5));

        return $date->setTime((int) $hour, (int) $minute);
    }
}
