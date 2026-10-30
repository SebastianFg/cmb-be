<?php

namespace App\Http\Controllers;

use App\Services\AvailabilityService;
use App\Services\ResponseJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Próximos días con la cantidad de franjas libres de cada uno: alimenta
     * la tira de días del portal y el selector de fecha del mostrador.
     */
    public function days(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $days = $this->availability->upcomingDays(
            (int) $validated['specialty_id'],
            isset($validated['professional_id']) ? (int) $validated['professional_id'] : null,
            isset($validated['days']) ? (int) $validated['days'] : null,
        );

        return ResponseJson::success('Días con disponibilidad.', [
            'horizon_days' => $this->availability->horizonDays(),
            'today' => $this->availability->today()->toDateString(),
            'days' => $days,
        ]);
    }

    /**
     * Franjas de un día. Devuelve el día completo con el estado de cada
     * franja (`free`, `taken`, `blocked`) porque el mostrador muestra también
     * las ocupadas, tachadas.
     */
    public function slots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = $this->availability->parseDate($validated['date']);
        $holiday = $this->availability->holidayOn($date);
        $now = $this->availability->now();

        $slots = array_map(
            fn (array $slot): array => [
                // El turno y la excepción quedan afuera: acá sólo hace falta
                // el estado, y el paciente del portal no tiene por qué ver
                // quién ocupa la franja.
                ...collect($slot)->except(['starts_at', 'ends_at', 'appointment', 'exception'])->all(),
                'is_bookable' => $slot['state'] === 'free' && $slot['starts_at']->greaterThan($now),
            ],
            $this->availability->daySlots(
                $date,
                (int) $validated['specialty_id'],
                isset($validated['professional_id']) ? (int) $validated['professional_id'] : null,
            ),
        );

        return ResponseJson::success('Franjas del día.', [
            'date' => $date->toDateString(),
            'holiday' => $holiday?->name,
            'slots' => $slots,
        ]);
    }
}
