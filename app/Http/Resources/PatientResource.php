<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Patient */
class PatientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'member_number' => $this->member_number,
            'blocked_until' => $this->blocked_until?->toDateString(),
            'is_active' => $this->is_active,
            // El portal queda activo recién cuando la ficha tiene credenciales.
            'has_portal_access' => filled($this->password),
            'nationality' => new NationalityResource($this->whenLoaded('nationality')),
            'health_insurance' => new HealthInsuranceResource($this->whenLoaded('healthInsurance')),
        ];
    }
}
