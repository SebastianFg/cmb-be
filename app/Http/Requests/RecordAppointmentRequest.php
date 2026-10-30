<?php

namespace App\Http\Requests;

use App\Services\AppointmentRecordService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Resultado del turno y observación administrativa (RF-20, RF-21). */
class RecordAppointmentRequest extends FormRequest
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
            // `present` exige que la clave venga: si faltara, un guardado de
            // sólo la observación borraría el resultado ya registrado.
            'attendance' => ['present', 'nullable', Rule::in(AppointmentRecordService::ATTENDANCE)],
            'notes' => ['nullable', 'string', 'max:'.AppointmentRecordService::NOTES_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'notes.max' => 'La observación admite hasta '.AppointmentRecordService::NOTES_MAX.' caracteres.',
        ];
    }
}
