<?php

namespace App\Http\Controllers;

use App\Http\Resources\HealthInsuranceResource;
use App\Models\HealthInsurance;
use App\Services\ResponseJson;
use Illuminate\Http\JsonResponse;

class HealthInsuranceController extends Controller
{
    public function index(): JsonResponse
    {
        $insurances = HealthInsurance::query()
            ->orderBy('name')
            ->orderBy('plan')
            ->get();

        return ResponseJson::success(
            'Obras sociales.',
            HealthInsuranceResource::collection($insurances),
        );
    }
}
