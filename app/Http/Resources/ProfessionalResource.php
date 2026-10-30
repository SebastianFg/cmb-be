<?php

namespace App\Http\Resources;

use App\Models\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Professional */
class ProfessionalResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, license_number: string|null, specialties: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name ?? '',
            'license_number' => $this->license_number,
            'specialties' => $this->whenLoaded(
                'specialties',
                fn () => $this->specialties->pluck('name')->values()->all(),
                [],
            ),
        ];
    }
}
