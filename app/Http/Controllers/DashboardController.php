<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Services\ResponseJson;
use App\Services\SlotCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Resumen del panel principal (RF-25 y RF-28). Todo se calcula al momento
 * sobre los turnos: no hay indicadores guardados.
 *
 * Un profesional ve sólo las cifras de su propia agenda.
 */
class DashboardController extends Controller
{
    private const TODAY_LIMIT = 10;

    private const CANCELLED = [
        AppointmentStatus::CancelledByProfessional->value,
        AppointmentStatus::CancelledByCenter->value,
    ];

    public function show(Request $request): JsonResponse
    {
        $settings = Setting::query()->find(1);
        $timezone = $settings?->timezone ?? config('app.timezone');
        $threshold = (int) ($settings?->no_show_alert_threshold ?? 0);

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $monthAgo = $today->subDays(30);

        $professionals = $this->scopedProfessionals($request->user());
        $professionalIds = $professionals->pluck('id')->all();

        $appointments = Appointment::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('starts_at', '>=', $today->subDays(40))
            ->where('starts_at', '<', $today->addDays(30))
            ->with(['patient:id,first_name,last_name', 'specialty:id,name'])
            ->get();

        $calculator = new SlotCalculator(
            Schedule::query()->whereIn('professional_id', $professionalIds)->get(),
            ScheduleException::query()->whereIn('professional_id', $professionalIds)->get(),
            $appointments,
            Holiday::query()->get(),
            $timezone,
        );

        $scopeName = $this->scopeName($request->user());

        return ResponseJson::success('Resumen del panel.', [
            'scope' => $scopeName,
            'today' => $today->toDateString(),
            'no_show_threshold' => $threshold,
            'kpis' => $this->kpis($appointments, $professionals, $calculator, $today, $monthAgo, $threshold),
            'alerts' => $this->alerts($appointments, $professionals, $calculator, $today, $monthAgo, $threshold, $timezone),
            'today_appointments' => $this->todayAppointments($appointments, $today, $timezone),
            'occupancy' => $this->occupancy($appointments, $professionals, $calculator, $today, $monthAgo),
            'demand' => $this->demand($appointments, $monthAgo, $today),
        ]);
    }

    /** @return Collection<int, Professional> */
    private function scopedProfessionals(mixed $actor): Collection
    {
        $query = Professional::query()->with('user:id,name');

        if ($actor instanceof User) {
            $own = $actor->professional()->first();

            if ($own) {
                return $query->whereKey($own->id)->get();
            }
        }

        return $query->get();
    }

    private function scopeName(mixed $actor): ?string
    {
        $own = $actor instanceof User ? $actor->professional()->with('user:id,name')->first() : null;

        return $own?->user?->name;
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, mixed>
     */
    private function kpis(
        Collection $appointments,
        Collection $professionals,
        SlotCalculator $calculator,
        CarbonImmutable $today,
        CarbonImmutable $monthAgo,
        int $threshold,
    ): array {
        $todays = $this->activeBetween($appointments, $today, $today);

        $published = $professionals->sum(
            fn (Professional $professional): int => $calculator->countPublishedSlots($professional->id, $today, $today),
        );

        $absence = $this->absenteeism($this->between($appointments, $monthAgo, $today));

        $cancelled = $this->between($appointments, $today->subDays(7), $today)
            ->filter(fn (Appointment $item): bool => in_array($item->status->value, self::CANCELLED, true));

        return [
            'today_total' => $todays->count(),
            'today_confirmed' => $todays->where('status', AppointmentStatus::Confirmed)->count(),
            'today_pending' => $todays->where('status', AppointmentStatus::Booked)->count(),
            'published_slots' => $published,
            'occupancy_rate' => $published > 0 ? round($todays->count() / $published * 100, 1) : null,
            'absence_rate' => $absence['rate'],
            'absence_over_threshold' => $absence['rate'] > $threshold,
            'cancelled_last_week' => $cancelled->count(),
            'cancelled_penalized' => $cancelled->where('is_penalized', true)->count(),
        ];
    }

    /**
     * @return list<array{type: string, title: string, text: string, date: string|null}>
     */
    private function alerts(
        Collection $appointments,
        Collection $professionals,
        SlotCalculator $calculator,
        CarbonImmutable $today,
        CarbonImmutable $monthAgo,
        int $threshold,
        string $timezone,
    ): array {
        $alerts = [];

        $exceptions = ScheduleException::query()
            ->whereIn('professional_id', $professionals->pluck('id'))
            ->whereBetween('starts_at', [$today, $today->addDays(7)])
            ->with('professional.user:id,name')
            ->get();

        foreach ($exceptions as $exception) {
            $start = $exception->starts_at->setTimezone($timezone);
            $end = $exception->ends_at->setTimezone($timezone);

            $alerts[] = [
                'type' => 'warning',
                'title' => $exception->type->value === 'absence' ? 'Ausencia declarada' : 'Franja bloqueada',
                'text' => sprintf(
                    '%s no atiende el %s de %s a %s (%s).',
                    $exception->professional?->user?->name ?? 'El profesional',
                    $start->translatedFormat('l j \d\e F'),
                    $start->format('H:i'),
                    $end->format('H:i'),
                    mb_strtolower((string) $exception->reason),
                ),
                'date' => $start->toDateString(),
            ];
        }

        $nextDay = $calculator->nextWorkingDay($today);
        $unconfirmed = $this->between($appointments, $nextDay, $nextDay)
            ->where('status', AppointmentStatus::Booked);

        if ($unconfirmed->isNotEmpty()) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Turnos sin confirmar',
                'text' => sprintf(
                    '%d %s del %s siguen en estado Reservado.',
                    $unconfirmed->count(),
                    $unconfirmed->count() === 1 ? 'turno' : 'turnos',
                    $nextDay->translatedFormat('l j \d\e F'),
                ),
                'date' => $nextDay->toDateString(),
            ];
        }

        $over = Specialty::query()->get()
            ->map(function (Specialty $specialty) use ($appointments, $monthAgo, $today): array {
                $rows = $this->between($appointments, $monthAgo, $today)
                    ->where('specialty_id', $specialty->id);

                return ['specialty' => $specialty, 'absence' => $this->absenteeism($rows)];
            })
            ->filter(fn (array $row): bool => $row['absence']['total'] >= 10 && $row['absence']['rate'] > $threshold)
            ->sortByDesc(fn (array $row): float => $row['absence']['rate'])
            ->values();

        if ($over->isNotEmpty()) {
            $worst = $over->first();

            $alerts[] = [
                'type' => 'warning',
                'title' => 'Ausentismo sobre umbral',
                'text' => sprintf(
                    '%s acumula %s%% de ausentes en los últimos 30 días (%d de %d turnos).',
                    $worst['specialty']->name,
                    $worst['absence']['rate'],
                    $worst['absence']['absent'],
                    $worst['absence']['total'],
                ),
                'date' => null,
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function todayAppointments(Collection $appointments, CarbonImmutable $today, string $timezone): array
    {
        return $this->between($appointments, $today, $today)
            ->sortBy(fn (Appointment $item): string => $item->starts_at->toIso8601String())
            ->take(self::TODAY_LIMIT)
            ->map(fn (Appointment $item): array => [
                'id' => $item->id,
                'time' => $item->starts_at->setTimezone($timezone)->format('H:i'),
                'patient' => trim(($item->patient?->first_name ?? '').' '.($item->patient?->last_name ?? '')),
                'professional' => $item->professional?->user?->name ?? '',
                'specialty' => $item->specialty?->name ?? '',
                'status' => $item->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{professional: string, rate: int}>
     */
    private function occupancy(
        Collection $appointments,
        Collection $professionals,
        SlotCalculator $calculator,
        CarbonImmutable $today,
        CarbonImmutable $monthAgo,
    ): array {
        return $professionals
            ->map(function (Professional $professional) use ($appointments, $calculator, $today, $monthAgo): array {
                $published = $calculator->countPublishedSlots($professional->id, $monthAgo, $today);
                $taken = $this->activeBetween($appointments, $monthAgo, $today)
                    ->where('professional_id', $professional->id)
                    ->count();

                return [
                    'professional' => $professional->user?->name ?? '',
                    'rate' => $published > 0 ? (int) round($taken / $published * 100) : 0,
                ];
            })
            ->sortByDesc('rate')
            ->values()
            ->all();
    }

    /**
     * @return list<array{specialty: string, count: int}>
     */
    private function demand(Collection $appointments, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->activeBetween($appointments, $from, $to)
            ->groupBy(fn (Appointment $item): string => $item->specialty?->name ?? '—')
            ->map(fn (Collection $rows, string $name): array => ['specialty' => $name, 'count' => $rows->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return Collection<int, Appointment> */
    private function between(Collection $appointments, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $appointments->filter(
            fn (Appointment $item): bool => $item->starts_at->greaterThanOrEqualTo($from)
                && $item->starts_at->lessThan($to->addDay()),
        );
    }

    /** Turnos vigentes: los cancelados no cuentan para ocupación ni demanda. */
    private function activeBetween(Collection $appointments, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->between($appointments, $from, $to)->reject(
            fn (Appointment $item): bool => in_array($item->status->value, self::CANCELLED, true),
        );
    }

    /** @return array{rate: float, absent: int, total: int} */
    private function absenteeism(Collection $appointments): array
    {
        $happened = $appointments->filter(fn (Appointment $item): bool => in_array(
            $item->status->value,
            [AppointmentStatus::Attended->value, AppointmentStatus::NoShow->value],
            true,
        ));

        $absent = $happened->where('status', AppointmentStatus::NoShow)->count();

        return [
            'rate' => $happened->isEmpty() ? 0.0 : round($absent / $happened->count() * 100, 1),
            'absent' => $absent,
            'total' => $happened->count(),
        ];
    }
}
