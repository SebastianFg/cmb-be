<?php

namespace App\Http\Resources;

use App\Models\Nationality;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nationality */
class NationalityResource extends JsonResource
{
    /** @return array{id: int, name: string, iso_code: string|null} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso_code' => $this->iso_code,
        ];
    }
}
