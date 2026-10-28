<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Patient */
class PatientAuthResource extends JsonResource
{
    private const ROLE = 'patient';

    /**
     * @return array{
     *     id: int,
     *     first_name: string,
     *     last_name: string,
     *     document_number: string,
     *     email: string|null,
     *     phone: string|null,
     *     is_active: bool,
     *     blocked_until: string|null,
     *     roles: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'document_number' => $this->document_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'blocked_until' => $this->blocked_until?->toIso8601String(),
            // Constante y no una relación: el paciente no se asocia por
            // `role_user`. Se informa para que el front lea el rol del mismo
            // campo en los dos tipos de cuenta.
            'roles' => [self::ROLE],
        ];
    }
}
