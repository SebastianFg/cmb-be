<?php

namespace App\Http\Controllers;

use App\Http\Resources\SpecialtyResource;
use App\Models\Specialty;
use App\Services\ResponseJson;
use Illuminate\Http\JsonResponse;

class SpecialtyController extends Controller
{
    public function index(): JsonResponse
    {
        $specialties = Specialty::query()->orderBy('name')->get();

        return ResponseJson::success('Especialidades.', SpecialtyResource::collection($specialties));
    }
}
