<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Cancelación de un turno (RF-18): el motivo queda registrado. */
class CancelAppointmentRequest extends FormRequest
{
    /** La ruta ya exige la sesión y el rol; el dueño lo controla el controlador. */
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
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Indicá el motivo de la cancelación: queda registrado en el turno.',
            'cancellation_reason.min' => 'Describí el motivo con un poco más de detalle.',
            'cancellation_reason.max' => 'El motivo admite hasta 200 caracteres.',
        ];
    }
}
