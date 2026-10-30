<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchedulingSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CatalogSeeder::class, SchedulingSeeder::class]);
});

function bookingActor(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function asDesk(string $role = 'reception')
{
    return test()->actingAs(bookingActor($role), 'sanctum');
}

/** El portal pide la habilidad `patient`, así que hace falta un token. */
function asPatient(Patient $patient)
{
    Sanctum::actingAs($patient, ['patient']);

    return test();
}

/** El proyecto devuelve los errores de validación dentro de `data`. */
function expectInvalid($response, string $field): string
{
    $response->assertUnprocessable();
    expect($response->json('data'))->toHaveKey($field);

    return $response->json("data.{$field}.0");
}

/**
 * Primer día y franja reservables de una especialidad, descubiertos con los
 * mismos endpoints que usa el formulario.
 *
 * @return array{date: string, slot: array<string, mixed>}
 */
function firstBookable(int $specialtyId): array
{
    $days = asDesk()->getJson("/api/v1/availability/days?specialty_id={$specialtyId}")
        ->assertOk()
        ->json('data.days');

    $day = collect($days)->first(fn (array $row): bool => $row['free'] > 0);
    expect($day)->not->toBeNull();

    $slots = asDesk()->getJson("/api/v1/availability/slots?specialty_id={$specialtyId}&date={$day['date']}")
        ->assertOk()
        ->json('data.slots');

    $slot = collect($slots)->first(fn (array $row): bool => $row['is_bookable']);
    expect($slot)->not->toBeNull();

    return ['date' => $day['date'], 'slot' => $slot];
}

function bookingPayload(int $specialtyId, Patient $patient, array $overrides = []): array
{
    ['date' => $date, 'slot' => $slot] = firstBookable($specialtyId);

    return [
        'patient_id' => $patient->id,
        'specialty_id' => $specialtyId,
        'professional_id' => $slot['professional_id'],
        'date' => $date,
        'start_time' => $slot['start_time'],
        'reason' => 'Control anual',
        ...$overrides,
    ];
}

/**
 * Franja de otro profesional a la misma hora: es la que permite provocar la
 * superposición del paciente sin chocar con la agenda del profesional.
 *
 * @return array<string, mixed>|null
 */
function otherProfessionalSlotAt(string $date, string $time, int $exceptProfessionalId): ?array
{
    foreach (Specialty::query()->pluck('id') as $specialtyId) {
        $slots = asDesk()->getJson("/api/v1/availability/slots?specialty_id={$specialtyId}&date={$date}")
            ->assertOk()
            ->json('data.slots');

        $slot = collect($slots)->first(fn (array $row): bool => $row['start_time'] === $time
            && $row['is_bookable']
            && $row['professional_id'] !== $exceptProfessionalId);

        if (filled($slot)) {
            return $slot;
        }
    }

    return null;
}

describe('catálogos de la reserva', function (): void {
    it('lista las especialidades ordenadas por nombre', function (): void {
        $names = asDesk()->getJson('/api/v1/specialties')->assertOk()->json('data.*.name');

        expect($names)->toHaveCount(5);
        expect($names)->toBe(collect($names)->sort()->values()->all());
    });

    it('filtra los profesionales por especialidad', function (): void {
        $specialty = Specialty::query()->firstOrFail();

        $rows = asDesk()->getJson("/api/v1/professionals?specialty_id={$specialty->id}")
            ->assertOk()
            ->json('data');

        expect($rows)->not->toBeEmpty();
        expect($rows[0])->toHaveKeys(['id', 'name', 'license_number', 'specialties']);
        expect(collect($rows)->pluck('specialties')->flatten())->each->toBe($specialty->name);
    });

    it('el paciente también lee los catálogos, porque el portal los necesita', function (): void {
        asPatient(Patient::query()->firstOrFail())
            ->getJson('/api/v1/specialties')
            ->assertOk();
    });
});

describe('disponibilidad', function (): void {
    it('devuelve catorce días sin domingos', function (): void {
        $specialty = Specialty::query()->firstOrFail();

        $data = asDesk()->getJson("/api/v1/availability/days?specialty_id={$specialty->id}")
            ->assertOk()
            ->json('data');

        expect($data['days'])->toHaveCount(14);
        expect($data['horizon_days'])->toBe(30);
        expect(collect($data['days'])->pluck('weekday'))->not->toContain(0);
        expect(collect($data['days'])->pluck('date')->all())
            ->toBe(collect($data['days'])->pluck('date')->sort()->values()->all());
    });

    it('marca el estado de cada franja del día', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        ['date' => $date] = firstBookable($specialty->id);

        $slots = asDesk()->getJson("/api/v1/availability/slots?specialty_id={$specialty->id}&date={$date}")
            ->assertOk()
            ->json('data.slots');

        expect($slots)->not->toBeEmpty();
        expect($slots[0])->toHaveKeys([
            'professional_id', 'professional', 'license_number', 'specialty_id',
            'room_id', 'room', 'date', 'start_time', 'end_time',
            'duration_minutes', 'state', 'is_bookable',
        ]);
        expect(collect($slots)->pluck('state')->unique())->each->toBeIn(['free', 'taken', 'blocked']);
    });

    it('no publica franjas en un feriado', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        ['date' => $date] = firstBookable($specialty->id);
        Holiday::query()->create(['date' => $date, 'name' => 'Día de prueba', 'is_nationwide' => true]);

        $data = asDesk()->getJson("/api/v1/availability/slots?specialty_id={$specialty->id}&date={$date}")
            ->assertOk()
            ->json('data');

        expect($data['holiday'])->toBe('Día de prueba');
        expect($data['slots'])->toBeEmpty();
    });

    it('la franja reservada deja de estar disponible', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient);

        asDesk()->postJson('/api/v1/appointments', $payload)->assertCreated();

        $slots = asDesk()->getJson("/api/v1/availability/slots?specialty_id={$specialty->id}&date={$payload['date']}")
            ->assertOk()
            ->json('data.slots');

        $slot = collect($slots)->firstWhere('start_time', $payload['start_time']);
        expect($slot['state'])->toBe('taken');
        expect($slot['is_bookable'])->toBeFalse();
    });

    it('exige sesión', function (): void {
        test()->getJson('/api/v1/availability/days?specialty_id=1')->assertUnauthorized();
    });
});

describe('alta desde el mostrador', function (): void {
    it('reserva el turno y devuelve el comprobante', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient);

        $data = asDesk()->postJson('/api/v1/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', AppointmentStatus::Booked->value)
            ->assertJsonPath('data.source', AppointmentSource::Desk->value)
            ->assertJsonPath('data.date', $payload['date'])
            ->assertJsonPath('data.start_time', $payload['start_time'])
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.reason', 'Control anual')
            ->json('data');

        expect($data['confirmation_code'])->toStartWith('TRN-');
        expect($data['room']['name'])->not->toBeEmpty();
        expect($data['professional']['name'])->not->toBeEmpty();
    });

    it('anota quién lo cargó', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        // El payload se arma antes de la sesión: descubrir la franja hace sus
        // propios pedidos y cambiaría el usuario autenticado.
        $payload = bookingPayload($specialty->id, $patient);
        $actor = bookingActor('reception');

        test()->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/appointments', $payload)
            ->assertCreated();

        expect(Appointment::query()->latest('id')->value('created_by'))->toBe($actor->id);
    });

    it('rechaza una franja que ya tomó otro paciente', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        [$first, $second] = Patient::query()->limit(2)->get();
        $payload = bookingPayload($specialty->id, $first);

        asDesk()->postJson('/api/v1/appointments', $payload)->assertCreated();

        $message = expectInvalid(
            asDesk()->postJson('/api/v1/appointments', [...$payload, 'patient_id' => $second->id]),
            'slot',
        );

        expect($message)->toContain("La franja de las {$payload['start_time']} ya no está disponible");
    });

    it('rechaza una fecha pasada', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient, [
            'date' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        expect(expectInvalid(asDesk()->postJson('/api/v1/appointments', $payload), 'date'))
            ->toBe('No se puede sacar un turno en una fecha pasada.');
    });

    it('rechaza una fecha fuera del horizonte de reserva', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient, [
            'date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        ]);

        expect(expectInvalid(asDesk()->postJson('/api/v1/appointments', $payload), 'date'))
            ->toBe('Solo se pueden reservar turnos dentro de los próximos 30 días.');
    });

    it('rechaza un feriado', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient);
        Holiday::query()->create(['date' => $payload['date'], 'name' => 'Día de prueba', 'is_nationwide' => true]);

        expect(expectInvalid(asDesk()->postJson('/api/v1/appointments', $payload), 'date'))
            ->toContain('el centro no atiende (Día de prueba)');
    });

    it('rechaza dos turnos superpuestos del mismo paciente (RNF-06)', function (): void {
        $patient = Patient::query()->firstOrFail();
        $first = bookingPayload(Specialty::query()->value('id'), $patient);

        asDesk()->postJson('/api/v1/appointments', $first)->assertCreated();

        $other = otherProfessionalSlotAt($first['date'], $first['start_time'], $first['professional_id']);
        expect($other)->not->toBeNull();

        $message = expectInvalid(asDesk()->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'specialty_id' => $other['specialty_id'],
            'professional_id' => $other['professional_id'],
            'date' => $first['date'],
            'start_time' => $other['start_time'],
        ]), 'slot');

        expect($message)->toContain('El paciente ya tiene un turno el');
        expect($message)->toContain('no puede tener dos turnos superpuestos');
    });

    it('acepta el turno aunque el paciente esté penalizado: la penalización sólo frena la autogestión', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $patient->update(['blocked_until' => CarbonImmutable::now()->addDays(5)]);

        asDesk()->postJson('/api/v1/appointments', bookingPayload($specialty->id, $patient))
            ->assertCreated();
    });

    it('exige el paciente', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, Patient::query()->firstOrFail());
        unset($payload['patient_id']);

        expect(expectInvalid(asDesk()->postJson('/api/v1/appointments', $payload), 'patient_id'))
            ->toBe('Buscá y seleccioná el paciente.');
    });

    it('limita el motivo a 200 caracteres', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, Patient::query()->firstOrFail(), [
            'reason' => str_repeat('a', 201),
        ]);

        expect(expectInvalid(asDesk()->postJson('/api/v1/appointments', $payload), 'reason'))
            ->toBe('El motivo admite hasta 200 caracteres.');
    });

    it('no la abre al paciente', function (): void {
        $patient = Patient::query()->firstOrFail();

        asPatient($patient)
            ->postJson('/api/v1/appointments', ['patient_id' => $patient->id])
            ->assertForbidden();
    });
});

describe('autogestión del paciente', function (): void {
    it('reserva para sí mismo y queda registrado como portal', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient);
        unset($payload['patient_id']);

        asPatient($patient)->postJson('/api/v1/portal/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.source', AppointmentSource::Portal->value)
            ->assertJsonPath('data.patient.id', $patient->id);

        expect(Appointment::query()->latest('id')->value('created_by'))->toBeNull();
    });

    it('no permite reservar a nombre de otro paciente', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        [$patient, $other] = Patient::query()->limit(2)->get();
        $payload = bookingPayload($specialty->id, $other);

        expectInvalid(
            asPatient($patient)->postJson('/api/v1/portal/appointments', $payload),
            'patient_id',
        );
    });

    it('frena al paciente penalizado', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = bookingPayload($specialty->id, $patient);
        unset($payload['patient_id']);
        $patient->update(['blocked_until' => CarbonImmutable::now()->addDays(5)]);

        $message = expectInvalid(
            asPatient($patient)->postJson('/api/v1/portal/appointments', $payload),
            'patient_id',
        );

        expect($message)->toContain('Tu cuenta tiene una penalización hasta el');
        expect($message)->toContain('podés sacar turno llamando al centro');
    });

    it('lista sus propios turnos y no los de otros', function (): void {
        $specialty = Specialty::query()->firstOrFail();
        [$patient, $other] = Patient::query()->limit(2)->get();
        $payload = bookingPayload($specialty->id, $patient);
        unset($payload['patient_id']);

        asPatient($patient)->postJson('/api/v1/portal/appointments', $payload)->assertCreated();

        $rows = asPatient($patient)->getJson('/api/v1/portal/appointments')->assertOk()->json('data');
        expect(collect($rows)->pluck('patient.id')->unique()->all())->toBe([$patient->id]);

        $otherRows = asPatient($other)->getJson('/api/v1/portal/appointments')->assertOk()->json('data');
        expect(collect($otherRows)->pluck('id'))->not->toContain($rows[0]['id']);
    });

    it('no abre la agenda del centro al paciente', function (): void {
        asPatient(Patient::query()->firstOrFail())
            ->getJson('/api/v1/appointments')
            ->assertForbidden();
    });
});

describe('agenda del centro', function (): void {
    it('lista los turnos del día ordenados por hora', function (): void {
        $today = CarbonImmutable::now('America/Argentina/Buenos_Aires')->toDateString();

        $rows = asDesk()->getJson("/api/v1/appointments?date={$today}")->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();
        expect(collect($rows)->pluck('date')->unique()->all())->toBe([$today]);
        expect(collect($rows)->pluck('start_time')->all())
            ->toBe(collect($rows)->pluck('start_time')->sort()->values()->all());
    });

    it('el profesional ve sólo su propia agenda', function (): void {
        $professional = Professional::query()->with('user')->whereHas('appointments')->firstOrFail();

        $rows = test()->actingAs($professional->user, 'sanctum')
            ->getJson('/api/v1/appointments')
            ->assertOk()
            ->json('data');

        expect($rows)->not->toBeEmpty();
        expect(collect($rows)->pluck('professional.id')->unique()->all())->toBe([$professional->id]);
    });

    it('filtra por paciente', function (): void {
        $appointment = Appointment::query()->firstOrFail();

        $rows = asDesk()->getJson("/api/v1/appointments?patient_id={$appointment->patient_id}")
            ->assertOk()
            ->json('data');

        expect(collect($rows)->pluck('patient.id')->unique()->all())->toBe([$appointment->patient_id]);
    });
});
