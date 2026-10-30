<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfessionalResource;
use App\Models\Professional;
use App\Services\ResponseJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessionalController extends Controller
{
    /** Con `specialty_id` devuelve sólo los que atienden esa especialidad. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        $professionals = Professional::query()
            ->with(['user:id,name', 'specialties:id,name'])
            ->when($validated['specialty_id'] ?? null, function ($query, int $specialtyId): void {
                $query->whereHas('specialties', fn ($inner) => $inner->whereKey($specialtyId));
            })
            ->get()
            ->sortBy(fn (Professional $professional): string => $professional->user?->name ?? '')
            ->values();

        return ResponseJson::success('Profesionales.', ProfessionalResource::collection($professionals));
    }
}
