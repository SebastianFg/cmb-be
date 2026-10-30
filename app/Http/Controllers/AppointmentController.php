<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\CancelAppointmentRequest;
use App\Http\Requests\RecordAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentRecordService;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\ResponseJson;
use App\Services\StatusCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    private const PAGE_SIZE = 20;

    private const RELATIONS = ['patient:id,first_name,last_name,document_number,email', 'professional.user:id,name', 'specialty:id,name', 'room:id,name'];

    public function __construct(
        private readonly BookingService $booking,
        private readonly AvailabilityService $availability,
        private readonly AppointmentRecordService $record,
    ) {}

    /**
     * Agenda del personal. El profesional ve sólo sus turnos; el mostrador
     * filtra por día, profesional, paciente y estado.
     */
    public function index(Request $request): JsonResponse
    {
        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when($this->ownProfessionalId($request->user()), function ($query, int $professionalId): void {
                $query->where('professional_id', $professionalId);
            })
            ->when($request->date('date'), function ($query, $date): void {
                $day = $this->availability->parseDate($date->format('Y-m-d'));
                $query->whereBetween('starts_at', [$day->utc(), $day->endOfDay()->utc()]);
            })
            ->when($request->integer('professional_id'), function ($query, int $id): void {
                $query->where('professional_id', $id);
            })
            ->when($request->integer('patient_id'), function ($query, int $id): void {
                $query->where('patient_id', $id);
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', $request->string('status')->value());
            })
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page') ?: self::PAGE_SIZE);

        return ResponseJson::success('Turnos.', AppointmentResource::collection($appointments));
    }

    /** Alta desde el mostrador (CU-01): recepción elige el paciente. */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $patient = Patient::query()->findOrFail($request->integer('patient_id'));

        return $this->booked($request, $patient);
    }

    /** Alta por autogestión (CU-02): el paciente es el de la sesión. */
    public function storeForPatient(StoreAppointmentRequest $request): JsonResponse
    {
        /** @var Patient $patient */
        $patient = $request->user();

        return $this->booked($request, $patient);
    }

    /** Turnos propios del paciente: los próximos primero. */
    public function mine(Request $request): JsonResponse
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->where('patient_id', $patient->id)
            ->when($request->string('when')->value() === 'upcoming', function ($query): void {
                $query->where('starts_at', '>=', $this->availability->now())
                    ->whereIn('status', [AppointmentStatus::Booked->value, AppointmentStatus::Confirmed->value]);
            })
            ->orderByDesc('starts_at')
            ->paginate($request->integer('per_page') ?: self::PAGE_SIZE);

        return ResponseJson::success('Mis turnos.', AppointmentResource::collection($appointments));
    }

    /** Un turno concreto: lo lee la pantalla de reprogramación. */
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertBelongsToActor($request->user(), $appointment);

        return ResponseJson::success(
            'Turno.',
            new AppointmentResource($appointment->load(self::RELATIONS)),
        );
    }

    /** Reprogramación (RF-18): el turno se mueve y libera la franja anterior. */
    public function reschedule(StoreAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->assertBelongsToActor($actor, $appointment);

        $this->booking->reschedule($appointment, $request->validated(), $actor);

        return ResponseJson::success(
            'Turno reprogramado.',
            new AppointmentResource($appointment->fresh()?->load(self::RELATIONS)),
        );
    }

    /** Resultado del turno y observación administrativa (RF-20, RF-21). */
    public function record(RecordAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->assertBelongsToActor($request->user(), $appointment);

        $this->record->record(
            $appointment,
            $request->input('attendance'),
            $request->input('notes'),
        );

        return ResponseJson::success(
            'Registro del turno guardado.',
            new AppointmentResource($appointment->fresh()?->load(self::RELATIONS)),
        );
    }

    /** Cancelación con motivo (RF-18): la franja vuelve a quedar libre. */
    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->assertBelongsToActor($actor, $appointment);

        $this->record->cancel($appointment, $actor, $request->string('cancellation_reason')->value());

        return ResponseJson::success(
            'Turno cancelado.',
            new AppointmentResource($appointment->fresh()?->load(self::RELATIONS)),
        );
    }

    /** El profesional sólo toca los turnos de su propia agenda. */
    private function assertBelongsToActor(mixed $actor, Appointment $appointment): void
    {
        $own = $this->ownProfessionalId($actor);

        abort_if(
            filled($own) && $appointment->professional_id !== $own,
            StatusCode::HTTP_FORBIDDEN,
            'El turno es de la agenda de otro profesional.',
        );
    }

    private function booked(StoreAppointmentRequest $request, Patient $patient): JsonResponse
    {
        $appointment = $this->booking->book(
            $request->validated(),
            $patient,
            $request->user(),
        );

        return ResponseJson::success(
            'Turno reservado.',
            new AppointmentResource($appointment->load(self::RELATIONS)),
            StatusCode::HTTP_CREATED,
        );
    }

    /**
     * El profesional sólo ve su propia agenda; el resto del personal, toda.
     * Mismo criterio que el panel: manda tener ficha de profesional.
     */
    private function ownProfessionalId(mixed $actor): ?int
    {
        return $actor instanceof User ? $actor->professional()->value('id') : null;
    }
}
