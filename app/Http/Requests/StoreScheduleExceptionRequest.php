<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ausencia o bloqueo de franja (RF-12). Las reglas que necesitan la agenda
 * —superposición y turnos en la franja— viven en `ScheduleExceptionService`.
 */
class StoreScheduleExceptionRequest extends FormRequest
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
            'professional_id' => ['required', 'integer', Rule::exists('professionals', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'type' => ['required', Rule::in(['absence', 'block'])],
            'reason' => ['required', 'string', 'min:3', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'professional_id.required' => 'Elegí el profesional.',
            'start_time.required' => 'Elegí el horario.',
            'end_time.required' => 'Elegí el horario.',
            'reason.required' => 'Indicá el motivo (por ejemplo: congreso, trámite personal).',
            'reason.min' => 'Indicá el motivo (por ejemplo: congreso, trámite personal).',
        ];
    }
}
