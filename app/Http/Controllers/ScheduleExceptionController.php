<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleExceptionRequest;
use App\Models\User;
use App\Services\ResponseJson;
use App\Services\ScheduleExceptionService;
use App\Services\StatusCode;
use Illuminate\Http\JsonResponse;

/** Ausencias y bloqueos de franja sobre la agenda (RF-12). */
class ScheduleExceptionController extends Controller
{
    public function __construct(private readonly ScheduleExceptionService $exceptions) {}

    public function store(StoreScheduleExceptionRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $own = $actor->professional()->value('id');
        $professionalId = (int) $request->integer('professional_id');

        abort_if(
            filled($own) && $professionalId !== $own,
            StatusCode::HTTP_FORBIDDEN,
            'Solo podés declarar ausencias sobre tu propia agenda.',
        );

        $exception = $this->exceptions->declare([
            ...$request->validated(),
            'professional_id' => $professionalId,
        ]);

        return ResponseJson::success(
            $exception->type->value === 'absence' ? 'Ausencia registrada.' : 'Franja bloqueada.',
            [
                'id' => $exception->id,
                'professional_id' => $exception->professional_id,
                'type' => $exception->type->value,
                'reason' => $exception->reason,
            ],
            StatusCode::HTTP_CREATED,
        );
    }
}
