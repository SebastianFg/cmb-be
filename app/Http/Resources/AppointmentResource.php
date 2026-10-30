<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    /**
     * Incluye fecha y hora ya pasadas al huso del centro: es lo que muestra
     * el comprobante y evita que cada cliente haga su propia conversión.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = Setting::query()->value('timezone') ?? config('app.timezone');
        $starts = $this->starts_at->setTimezone($timezone);
        $ends = $this->ends_at->setTimezone($timezone);

        return [
            'id' => $this->id,
            'confirmation_code' => $this->confirmation_code,
            'date' => $starts->toDateString(),
            'start_time' => $starts->format('H:i'),
            'end_time' => $ends->format('H:i'),
            'status' => $this->status->value,
            'source' => $this->source->value,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'is_penalized' => $this->is_penalized,
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'name' => trim("{$this->patient->first_name} {$this->patient->last_name}"),
                'document_number' => $this->patient->document_number,
                'email' => $this->patient->email,
            ]),
            'professional' => $this->whenLoaded('professional', fn (): array => [
                'id' => $this->professional->id,
                'name' => $this->professional->user?->name ?? '',
            ]),
            'specialty' => $this->whenLoaded('specialty', fn (): array => [
                'id' => $this->specialty->id,
                'name' => $this->specialty->name,
            ]),
            'room' => $this->whenLoaded('room', fn (): ?array => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ] : null),
        ];
    }
}
