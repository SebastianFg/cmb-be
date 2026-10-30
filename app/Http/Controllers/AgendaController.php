<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ResponseJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agenda diaria del centro (RF-26, mockup 06). Devuelve las franjas del día
 * de cada profesional que atiende, con el turno o el bloqueo que las ocupa.
 *
 * La grilla —qué fila abarca cada celda— la arma el cliente: acá sólo viaja
 * el dato.
 */
class AgendaController extends Controller
{
    private const CANCELLED = [
        AppointmentStatus::CancelledByProfessional->value,
        AppointmentStatus::CancelledByCenter->value,
    ];

    public function __construct(private readonly AvailabilityService $availability) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        $date = isset($validated['date'])
            ? $this->availability->parseDate($validated['date'])
            : $this->availability->today();

        // El profesional ve sólo su columna, elija lo que elija el filtro.
        $own = $this->ownProfessionalId($request->user());
        $professionalId = $own ?? (isset($validated['professional_id']) ? (int) $validated['professional_id'] : null);

        $slots = $this->availability->agendaSlots($date, $professionalId);
        $holiday = $this->availability->holidayOn($date);

        return ResponseJson::success('Agenda del día.', [
            'date' => $date->toDateString(),
            'weekday' => $date->dayOfWeek,
            'holiday' => $holiday?->name,
            'is_own_agenda' => filled($own),
            'professionals' => $this->columns($slots),
            'slots' => array_map(fn (array $slot): array => $this->toRow($slot), $slots),
            'cancelled' => $this->cancelledOn($date, $professionalId),
            'stats' => $this->stats($slots),
        ]);
    }

    /** Profesionales que atienden ese día, en el orden de las columnas. */
    private function columns(array $slots): array
    {
        return collect($slots)
            ->groupBy('professional_id')
            ->map(fn ($group): array => [
                'id' => $group[0]['professional_id'],
                'name' => $group[0]['professional'],
                'license_number' => $group[0]['license_number'],
                'specialty' => $group[0]['specialty'],
                'room' => $group[0]['room'],
                'duration_minutes' => $group[0]['duration_minutes'],
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function toRow(array $slot): array
    {
        $appointment = $slot['appointment'];

        return [
            'professional_id' => $slot['professional_id'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'state' => $slot['state'],
            'appointment' => $appointment ? $this->appointmentPayload($appointment) : null,
            'exception' => $slot['exception'] ? $this->exceptionPayload($slot['exception'], $slot) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function appointmentPayload(Appointment $appointment): array
    {
        $patient = $appointment->patient;

        return [
            'id' => $appointment->id,
            'status' => $appointment->status->value,
            'source' => $appointment->source->value,
            'confirmation_code' => $appointment->confirmation_code,
            // El panel de detalle se arma con esto: no hace falta un pedido
            // más al elegir un turno de la grilla.
            'reason' => $appointment->reason,
            'notes' => $appointment->notes,
            'cancellation_reason' => $appointment->cancellation_reason,
            'room' => $appointment->room?->name,
            'patient' => [
                'id' => $patient?->id,
                'first_name' => $patient?->first_name,
                'last_name' => $patient?->last_name,
                'document_number' => $patient?->document_number,
                'health_insurance' => $patient?->healthInsurance?->name,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function exceptionPayload(mixed $exception, array $slot): array
    {
        $timezone = $this->availability->timezone();

        return [
            'id' => $exception->id,
            'type' => $exception->type->value,
            'reason' => $exception->reason,
            'start_time' => $exception->starts_at->setTimezone($timezone)->format('H:i'),
            'end_time' => $exception->ends_at->setTimezone($timezone)->format('H:i'),
        ];
    }

    /**
     * Turnos cancelados del día: la franja vuelve a estar libre, pero la
     * agenda deja la marca de que ahí hubo un turno.
     *
     * @return list<array{professional_id: int, start_time: string}>
     */
    private function cancelledOn(CarbonImmutable $date, ?int $professionalId): array
    {
        $timezone = $this->availability->timezone();

        return Appointment::query()
            ->whereIn('status', self::CANCELLED)
            ->whereBetween('starts_at', [$date->utc(), $date->endOfDay()->utc()])
            ->when($professionalId, fn ($query) => $query->where('professional_id', $professionalId))
            ->get(['professional_id', 'starts_at'])
            ->map(fn (Appointment $item): array => [
                'professional_id' => $item->professional_id,
                'start_time' => $item->starts_at->setTimezone($timezone)->format('H:i'),
            ])
            ->values()
            ->all();
    }

    /** @return array{total: int, attended: int, no_show: int, free: int} */
    private function stats(array $slots): array
    {
        $taken = collect($slots)->where('state', 'taken')->pluck('appointment');

        return [
            'total' => $taken->count(),
            'attended' => $taken->where('status', AppointmentStatus::Attended)->count(),
            'no_show' => $taken->where('status', AppointmentStatus::NoShow)->count(),
            'free' => collect($slots)->where('state', 'free')->count(),
        ];
    }

    private function ownProfessionalId(mixed $actor): ?int
    {
        return $actor instanceof User ? $actor->professional()->value('id') : null;
    }
}
