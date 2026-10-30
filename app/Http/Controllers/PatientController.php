<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Services\ResponseJson;
use App\Services\StatusCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    private const PAGE_SIZE = 10;

    /**
     * Listado del mostrador: busca por documento o apellido y filtra por
     * cobertura, acceso al portal y penalización activa.
     */
    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            ->with(['nationality', 'healthInsurance'])
            ->when($request->string('search')->trim()->value(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('document_number', 'like', "{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%");
                });
            })
            ->when($request->integer('health_insurance_id'), function ($query, int $insuranceId): void {
                $query->where('health_insurance_id', $insuranceId);
            })
            ->when($request->filled('portal'), function ($query) use ($request): void {
                $request->string('portal')->value() === 'active'
                    ? $query->whereNotNull('password')
                    : $query->whereNull('password');
            })
            ->when($request->filled('penalty'), function ($query) use ($request): void {
                $request->string('penalty')->value() === 'yes'
                    ? $query->where('blocked_until', '>=', now())
                    : $query->where(function ($inner): void {
                        $inner->whereNull('blocked_until')->orWhere('blocked_until', '<', now());
                    });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page') ?: self::PAGE_SIZE);

        return ResponseJson::success('Pacientes.', PatientResource::collection($patients));
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $patient = Patient::query()->create($request->validated());

        return ResponseJson::success(
            'Paciente registrado.',
            new PatientResource($patient->load(['nationality', 'healthInsurance'])),
            StatusCode::HTTP_CREATED,
        );
    }
}
