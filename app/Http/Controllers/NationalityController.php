<?php

namespace App\Http\Controllers;

use App\Http\Resources\NationalityResource;
use App\Models\Nationality;
use App\Services\ResponseJson;
use Illuminate\Http\JsonResponse;

class NationalityController extends Controller
{
    public function index(): JsonResponse
    {
        $nationalities = Nationality::query()->orderBy('name')->get();

        return ResponseJson::success(
            'Nacionalidades.',
            NationalityResource::collection($nationalities),
        );
    }
}
