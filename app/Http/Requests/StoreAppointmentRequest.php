<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Forma del pedido de turno. Las reglas de negocio (horizonte, feriado,
 * franja libre, superposición del paciente) viven en `BookingService`,
 * porque necesitan la agenda completa.
 *
 * `patient_id` sólo llega desde el mostrador: en el portal el paciente es
 * el de la sesión.
 */
class StoreAppointmentRequest extends FormRequest
{
    /** La ruta ya exige la sesión y el rol correspondiente. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => [
                // Al reprogramar el paciente no cambia: sale del turno.
                $this->isPortal() || $this->isReschedule() ? 'prohibited' : 'required',
                'integer',
                Rule::exists('patients', 'id'),
            ],
            'specialty_id' => ['required', 'integer', Rule::exists('specialties', 'id')],
            'professional_id' => ['required', 'integer', Rule::exists('professionals', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function isPortal(): bool
    {
        return $this->routeIs('appointments.portal.store');
    }

    public function isReschedule(): bool
    {
        return $this->routeIs('appointments.reschedule');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'patient_id.required' => 'Buscá y seleccioná el paciente.',
            'patient_id.exists' => 'Buscá y seleccioná el paciente.',
            'specialty_id.required' => 'Elegí la especialidad.',
            'professional_id.required' => 'Elegí una franja disponible.',
            'date.required' => 'Elegí el día.',
            'start_time.required' => 'Elegí una franja disponible.',
            'reason.max' => 'El motivo admite hasta 200 caracteres.',
        ];
    }
}
