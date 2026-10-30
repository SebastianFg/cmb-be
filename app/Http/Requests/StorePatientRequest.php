<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    /** La ruta ya exige sesión del personal con el rol correspondiente. */
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
            'document_number' => ['required', 'string', 'digits_between:7,8', Rule::unique('patients', 'document_number')],
            'first_name' => ['required', 'string', 'min:2', 'max:60'],
            'last_name' => ['required', 'string', 'min:2', 'max:60'],
            'birth_date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYears(120)->toDateString()],
            'nationality_id' => ['required', 'integer', Rule::exists('nationalities', 'id')],
            'phone' => ['required', 'string', 'digits:10'],
            'email' => [
                'nullable',
                'email',
                'max:80',
                Rule::unique('patients', 'email'),
                Rule::unique('users', 'email'),
            ],
            'health_insurance_id' => ['required', 'integer', Rule::exists('health_insurances', 'id')],
            'member_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{4,20}$/'],
        ];
    }

    /** El documento y el teléfono se guardan sin puntos ni espacios. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_number' => $this->digitsOf($this->input('document_number')),
            'phone' => $this->digitsOf($this->input('phone')),
            'email' => filled($this->input('email'))
                ? Str::lower(trim((string) $this->input('email')))
                : null,
        ]);
    }

    private function digitsOf(mixed $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document_number.digits_between' => 'El documento debe tener 7 u 8 dígitos, sin puntos ni espacios.',
            'document_number.unique' => 'Ya existe una ficha con ese documento.',
            'phone.digits' => 'El teléfono debe tener 10 dígitos: característica sin 0 y número sin 15.',
            'email.unique' => 'Ese correo ya está registrado.',
            'member_number.regex' => 'El número de afiliado admite letras, números y guiones (4 a 20 caracteres).',
        ];
    }
}
