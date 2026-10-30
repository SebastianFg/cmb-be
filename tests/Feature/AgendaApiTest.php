<?php

use App\Enums\AppointmentStatus;
use App\Enums\ExceptionType;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Role;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchedulingSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CatalogSeeder::class, SchedulingSeeder::class]);
});

function agendaActor(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function asAgendaStaff(string $role = 'reception')
{
    return test()->actingAs(agendaActor($role), 'sanctum');
}

function expectAgendaInvalid($response, string $field): string
{
    $response->assertUnprocessable();
    expect($response->json('data'))->toHaveKey($field);

    return $response->json("data.{$field}.0");
}

function centerToday(): CarbonImmutable
{
    return CarbonImmutable::now(Setting::query()->value('timezone'))->startOfDay();
}

/** Un turno activo del día que se pida, con su profesional. */
function appointmentOn(CarbonImmutable $date): Appointment
{
    return Appointment::query()
        ->whereBetween('starts_at', [$date->utc(), $date->endOfDay()->utc()])
        ->whereNotIn('status', ['cancelled_by_professional', 'cancelled_by_center'])
        ->orderBy('starts_at')
        ->firstOrFail();
}

/** Primer día que el seeder dejó con turnos, mirando hacia adelante. */
function dayWithAppointments(): CarbonImmutable
{
    $next = Appointment::query()
        ->where('starts_at', '>=', centerToday()->utc())
        ->orderBy('starts_at')
        ->firstOrFail();

    return CarbonImmutable::parse(
        $next->starts_at->setTimezone(Setting::query()->value('timezone'))->toDateString(),
        Setting::query()->value('timezone'),
    );
}

/**
 * Día futuro con turnos: las franjas de hoy que ya pasaron no son
 * reservables, así que la reprogramación necesita un día por delante.
 */
function futureDayWithAppointments(): CarbonImmutable
{
    $timezone = Setting::query()->value('timezone');

    $next = Appointment::query()
        ->where('starts_at', '>=', centerToday()->addDay()->utc())
        ->orderBy('starts_at')
        ->firstOrFail();

    return CarbonImmutable::parse(
        $next->starts_at->setTimezone($timezone)->toDateString(),
        $timezone,
    );
}

describe('tablero de la agenda', function (): void {
    it('arma las columnas y las franjas del día', function (): void {
        $date = dayWithAppointments()->toDateString();

        $data = asAgendaStaff()->getJson("/api/v1/agenda?date={$date}")->assertOk()->json('data');

        expect($data['date'])->toBe($date);
        expect($data['holiday'])->toBeNull();
        expect($data['professionals'])->not->toBeEmpty();
        expect($data['professionals'][0])->toHaveKeys([
            'id', 'name', 'license_number', 'specialty', 'room', 'duration_minutes',
        ]);
        expect($data['slots'][0])->toHaveKeys([
            'professional_id', 'start_time', 'end_time', 'state', 'appointment', 'exception',
        ]);
        expect(collect($data['slots'])->pluck('state')->unique())
            ->each->toBeIn(['free', 'taken', 'blocked']);
    });

    it('trae el paciente de cada turno tomado', function (): void {
        $date = dayWithAppointments()->toDateString();

        $slots = asAgendaStaff()->getJson("/api/v1/agenda?date={$date}")->assertOk()->json('data.slots');
        $taken = collect($slots)->firstWhere('state', 'taken');

        expect($taken['appointment'])->toHaveKeys(['id', 'status', 'source', 'confirmation_code', 'patient']);
        expect($taken['appointment']['patient'])->toHaveKeys([
            'id', 'first_name', 'last_name', 'document_number', 'health_insurance',
        ]);
    });

    it('cuenta turnos, atendidos, ausentes y franjas libres', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);
        $appointment->update(['status' => AppointmentStatus::NoShow]);

        $data = asAgendaStaff()->getJson("/api/v1/agenda?date={$date->toDateString()}")->assertOk()->json('data');

        expect($data['stats']['total'])->toBeGreaterThan(0);
        expect($data['stats']['no_show'])->toBe(1);
        expect($data['stats']['free'])->toBeGreaterThan(0);
    });

    it('no publica franjas en un feriado', function (): void {
        $date = dayWithAppointments()->toDateString();
        Holiday::query()->create([
            'date' => $date,
            'name' => 'Día de prueba',
            'is_nationwide' => true,
        ]);

        $data = asAgendaStaff()->getJson("/api/v1/agenda?date={$date}")->assertOk()->json('data');

        expect($data['holiday'])->toBe('Día de prueba');
        expect($data['slots'])->toBeEmpty();
        expect($data['professionals'])->toBeEmpty();
    });

    it('filtra por profesional', function (): void {
        $date = dayWithAppointments()->toDateString();
        $professionalId = appointmentOn(dayWithAppointments())->professional_id;

        $data = asAgendaStaff()
            ->getJson("/api/v1/agenda?date={$date}&professional_id={$professionalId}")
            ->assertOk()
            ->json('data');

        expect($data['professionals'])->toHaveCount(1);
        expect(collect($data['slots'])->pluck('professional_id')->unique()->all())->toBe([$professionalId]);
    });

    it('el profesional ve sólo su columna aunque pida otra', function (): void {
        $date = dayWithAppointments()->toDateString();
        $mine = Professional::query()->with('user')->whereHas('schedules')->firstOrFail();
        $other = Professional::query()->whereKeyNot($mine->id)->firstOrFail();

        $data = test()->actingAs($mine->user, 'sanctum')
            ->getJson("/api/v1/agenda?date={$date}&professional_id={$other->id}")
            ->assertOk()
            ->json('data');

        expect($data['is_own_agenda'])->toBeTrue();
        expect(collect($data['slots'])->pluck('professional_id')->unique()->all())->toBe([$mine->id]);
    });

    it('marca las franjas bloqueadas con su motivo', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);
        // Una franja libre del mismo profesional, al cierre del día.
        $starts = $appointment->starts_at->copy()->addHours(3);

        ScheduleException::query()->create([
            'professional_id' => $appointment->professional_id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(30),
            'type' => ExceptionType::Block,
            'reason' => 'Reunión de equipo',
        ]);

        $slots = asAgendaStaff()->getJson("/api/v1/agenda?date={$date->toDateString()}")->assertOk()->json('data.slots');
        $blocked = collect($slots)->firstWhere('state', 'blocked');

        expect($blocked)->not->toBeNull();
        expect($blocked['exception']['reason'])->toBe('Reunión de equipo');
        expect($blocked['exception']['type'])->toBe('block');
    });

    it('no la abre al paciente', function (): void {
        Sanctum::actingAs(Patient::query()->firstOrFail(), ['patient']);

        test()->getJson('/api/v1/agenda')->assertForbidden();
    });
});

describe('resultado del turno', function (): void {
    it('registra presente el día del turno', function (): void {
        $appointment = appointmentOn(centerToday());

        asAgendaStaff()->patchJson("/api/v1/appointments/{$appointment->id}", [
            'attendance' => 'present',
            'notes' => 'Llegó con la credencial.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Confirmed->value)
            ->assertJsonPath('data.notes', 'Llegó con la credencial.');
    });

    it('registra atendido una vez que el turno empezó', function (): void {
        $appointment = appointmentOn(centerToday());
        $appointment->update(['starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30)]);

        asAgendaStaff()->patchJson("/api/v1/appointments/{$appointment->id}", [
            'attendance' => 'attended',
            'notes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Attended->value);

        expect($appointment->fresh()->attended_at)->not->toBeNull();
    });

    it('rechaza el resultado de un turno que todavía no empezó', function (): void {
        $appointment = appointmentOn(dayWithAppointments()->addDays(1)->isWeekend()
            ? dayWithAppointments()
            : dayWithAppointments());
        $appointment->update(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30)]);

        $message = expectAgendaInvalid(
            asAgendaStaff()->patchJson("/api/v1/appointments/{$appointment->id}", [
                'attendance' => 'attended',
                'notes' => null,
            ]),
            'attendance',
        );

        expect($message)->toBe('El turno todavía no empezó: no se puede registrar el resultado.');
    });

    it('vuelve el turno a reservado si se borra el resultado', function (): void {
        $appointment = appointmentOn(centerToday());
        $appointment->update(['status' => AppointmentStatus::Confirmed]);

        asAgendaStaff()->patchJson("/api/v1/appointments/{$appointment->id}", [
            'attendance' => null,
            'notes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Booked->value);
    });

    it('limita la observación a 500 caracteres', function (): void {
        $appointment = appointmentOn(centerToday());

        expectAgendaInvalid(
            asAgendaStaff()->patchJson("/api/v1/appointments/{$appointment->id}", [
                'attendance' => null,
                'notes' => str_repeat('a', 501),
            ]),
            'notes',
        );
    });

    it('un profesional no toca los turnos de otro', function (): void {
        $appointment = appointmentOn(centerToday());
        $other = Professional::query()
            ->with('user')
            ->whereKeyNot($appointment->professional_id)
            ->firstOrFail();

        test()->actingAs($other->user, 'sanctum')
            ->patchJson("/api/v1/appointments/{$appointment->id}", ['attendance' => null, 'notes' => null])
            ->assertForbidden();
    });
});

describe('cancelación del turno', function (): void {
    it('cancela con motivo y libera la franja', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);

        asAgendaStaff()->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'cancellation_reason' => 'El paciente avisó por teléfono que no puede asistir',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::CancelledByCenter->value);

        $slots = asAgendaStaff()->getJson("/api/v1/agenda?date={$date->toDateString()}")->assertOk()->json('data');
        $time = $appointment->fresh()->starts_at->setTimezone(Setting::query()->value('timezone'))->format('H:i');

        expect(collect($slots['cancelled'])->pluck('start_time'))->toContain($time);
        expect(collect($slots['slots'])
            ->where('professional_id', $appointment->professional_id)
            ->firstWhere('start_time', $time)['state'])->toBe('free');
    });

    it('exige un motivo con algo de detalle', function (): void {
        $appointment = appointmentOn(dayWithAppointments());

        expect(expectAgendaInvalid(
            asAgendaStaff()->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
                'cancellation_reason' => 'no',
            ]),
            'cancellation_reason',
        ))->toBe('Describí el motivo con un poco más de detalle.');
    });

    it('la cancela el profesional sobre su agenda y no penaliza al paciente', function (): void {
        $appointment = appointmentOn(centerToday());
        $professional = Professional::query()->with('user')->findOrFail($appointment->professional_id);

        test()->actingAs($professional->user, 'sanctum')
            ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
                'cancellation_reason' => 'Cambio en la agenda del profesional',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::CancelledByProfessional->value);

        expect($appointment->fresh()->is_penalized)->toBeFalse();
        expect($appointment->patient->fresh()->blocked_until)->toBeNull();
    });

    it('penaliza al paciente cuando el centro cancela fuera de término', function (): void {
        // La ventana del seed es de 24 h: un turno de hoy siempre está fuera.
        $appointment = appointmentOn(centerToday());

        asAgendaStaff()->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'cancellation_reason' => 'El paciente avisó que no puede asistir',
        ])->assertOk();

        expect($appointment->fresh()->is_penalized)->toBeTrue();
        expect($appointment->patient->fresh()->blocked_until)->not->toBeNull();
    });

    it('no cancela dos veces el mismo turno', function (): void {
        $appointment = appointmentOn(dayWithAppointments());
        $payload = ['cancellation_reason' => 'Motivo suficiente para cancelar'];

        asAgendaStaff()->postJson("/api/v1/appointments/{$appointment->id}/cancel", $payload)->assertOk();

        expectAgendaInvalid(
            asAgendaStaff()->postJson("/api/v1/appointments/{$appointment->id}/cancel", $payload),
            'appointment',
        );
    });
});

describe('ausencias y bloqueos', function (): void {
    it('registra una ausencia sobre una franja libre', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);
        $timezone = Setting::query()->value('timezone');
        $start = $appointment->starts_at->setTimezone($timezone)->addHours(3);

        asAgendaStaff()->postJson('/api/v1/schedule-exceptions', [
            'professional_id' => $appointment->professional_id,
            'date' => $date->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i'),
            'type' => 'absence',
            'reason' => 'Congreso',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'absence');

        expect(ScheduleException::query()->where('reason', 'Congreso')->exists())->toBeTrue();
    });

    it('rechaza una franja que tiene turnos', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);
        $timezone = Setting::query()->value('timezone');
        $start = $appointment->starts_at->setTimezone($timezone);

        $message = expectAgendaInvalid(
            asAgendaStaff()->postJson('/api/v1/schedule-exceptions', [
                'professional_id' => $appointment->professional_id,
                'date' => $date->toDateString(),
                'start_time' => $start->format('H:i'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i'),
                'type' => 'block',
                'reason' => 'Mantenimiento',
            ]),
            'start_time',
        );

        expect($message)->toContain('en esa franja');
        expect($message)->toContain('Reprogramalos o cancelalos antes de bloquearla');
    });

    it('rechaza dos bloqueos superpuestos', function (): void {
        $date = dayWithAppointments();
        $appointment = appointmentOn($date);
        $timezone = Setting::query()->value('timezone');
        $start = $appointment->starts_at->setTimezone($timezone)->addHours(3);

        $payload = [
            'professional_id' => $appointment->professional_id,
            'date' => $date->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i'),
            'type' => 'block',
            'reason' => 'Reunión de equipo',
        ];

        asAgendaStaff()->postJson('/api/v1/schedule-exceptions', $payload)->assertCreated();

        expect(expectAgendaInvalid(
            asAgendaStaff()->postJson('/api/v1/schedule-exceptions', $payload),
            'start_time',
        ))->toBe('Ya hay una ausencia o un bloqueo que se superpone con ese horario.');
    });

    it('rechaza una fecha pasada', function (): void {
        $professional = Professional::query()->firstOrFail();

        expect(expectAgendaInvalid(
            asAgendaStaff()->postJson('/api/v1/schedule-exceptions', [
                'professional_id' => $professional->id,
                'date' => centerToday()->subDay()->toDateString(),
                'start_time' => '09:00',
                'end_time' => '10:00',
                'type' => 'absence',
                'reason' => 'Trámite personal',
            ]),
            'start_time',
        ))->toBe('No se puede bloquear una fecha pasada.');
    });

    it('exige que el fin sea posterior al inicio', function (): void {
        $professional = Professional::query()->firstOrFail();

        expect(expectAgendaInvalid(
            asAgendaStaff()->postJson('/api/v1/schedule-exceptions', [
                'professional_id' => $professional->id,
                'date' => centerToday()->addDay()->toDateString(),
                'start_time' => '11:00',
                'end_time' => '10:00',
                'type' => 'absence',
                'reason' => 'Trámite personal',
            ]),
            'end_time',
        ))->toBe('La hora de fin tiene que ser posterior a la de inicio.');
    });

    it('el profesional sólo bloquea su propia agenda', function (): void {
        $mine = Professional::query()->with('user')->firstOrFail();
        $other = Professional::query()->whereKeyNot($mine->id)->firstOrFail();

        test()->actingAs($mine->user, 'sanctum')
            ->postJson('/api/v1/schedule-exceptions', [
                'professional_id' => $other->id,
                'date' => centerToday()->addDay()->toDateString(),
                'start_time' => '09:00',
                'end_time' => '10:00',
                'type' => 'absence',
                'reason' => 'Congreso',
            ])
            ->assertForbidden();
    });
});

describe('reprogramación', function (): void {
    it('mueve el turno y conserva paciente y código', function (): void {
        $appointment = appointmentOn(futureDayWithAppointments());
        $timezone = Setting::query()->value('timezone');
        $target = $appointment->starts_at->setTimezone($timezone)->addHours(2);

        asAgendaStaff()->putJson("/api/v1/appointments/{$appointment->id}", [
            'specialty_id' => $appointment->specialty_id,
            'professional_id' => $appointment->professional_id,
            'date' => $target->toDateString(),
            'start_time' => $target->format('H:i'),
            'reason' => 'Cambio de horario',
        ])
            ->assertOk()
            ->assertJsonPath('data.start_time', $target->format('H:i'))
            ->assertJsonPath('data.confirmation_code', $appointment->confirmation_code)
            ->assertJsonPath('data.patient.id', $appointment->patient_id)
            ->assertJsonPath('data.status', AppointmentStatus::Booked->value);
    });

    it('acepta dejarlo donde está: su propia franja no lo bloquea', function (): void {
        $appointment = appointmentOn(futureDayWithAppointments());
        $timezone = Setting::query()->value('timezone');
        $starts = $appointment->starts_at->setTimezone($timezone);

        asAgendaStaff()->putJson("/api/v1/appointments/{$appointment->id}", [
            'specialty_id' => $appointment->specialty_id,
            'professional_id' => $appointment->professional_id,
            'date' => $starts->toDateString(),
            'start_time' => $starts->format('H:i'),
        ])->assertOk();
    });

    it('no admite el paciente en el cuerpo: sale del turno', function (): void {
        $appointment = appointmentOn(futureDayWithAppointments());
        $timezone = Setting::query()->value('timezone');
        $starts = $appointment->starts_at->setTimezone($timezone);

        expectAgendaInvalid(
            asAgendaStaff()->putJson("/api/v1/appointments/{$appointment->id}", [
                'patient_id' => Patient::query()->value('id'),
                'specialty_id' => $appointment->specialty_id,
                'professional_id' => $appointment->professional_id,
                'date' => $starts->toDateString(),
                'start_time' => $starts->format('H:i'),
            ]),
            'patient_id',
        );
    });

    it('no reprograma un turno cancelado', function (): void {
        $appointment = appointmentOn(futureDayWithAppointments());
        $appointment->update(['status' => AppointmentStatus::CancelledByCenter]);
        $timezone = Setting::query()->value('timezone');
        $starts = $appointment->starts_at->setTimezone($timezone);

        expect(expectAgendaInvalid(
            asAgendaStaff()->putJson("/api/v1/appointments/{$appointment->id}", [
                'specialty_id' => $appointment->specialty_id,
                'professional_id' => $appointment->professional_id,
                'date' => $starts->toDateString(),
                'start_time' => $starts->format('H:i'),
            ]),
            'appointment',
        ))->toBe('El turno ya está cerrado: no se puede reprogramar.');
    });

    it('devuelve el turno para armar el formulario', function (): void {
        $appointment = appointmentOn(futureDayWithAppointments());

        asAgendaStaff()->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.patient.id', $appointment->patient_id);
    });
});
