<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\Specialty;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    expect(DB::getDriverName())->toBe('pgsql');
});

it('defines all appointment and schedule exclusions as GiST constraints', function () {
    $constraints = collect(DB::select(<<<'SQL'
        SELECT tables.relname AS table_name, pg_get_constraintdef(constraints.oid) AS definition
        FROM pg_constraint AS constraints
        JOIN pg_class AS tables ON tables.oid = constraints.conrelid
        WHERE constraints.contype = 'x'
          AND tables.relname IN ('appointments', 'schedules')
        SQL));

    expect($constraints->where('table_name', 'appointments'))->toHaveCount(3)
        ->and($constraints->where('table_name', 'schedules'))->toHaveCount(2);

    foreach ($constraints as $constraint) {
        expect($constraint->definition)->toContain('EXCLUDE USING gist');
    }
});

it('enforces the settings singleton id', function () {
    Setting::factory()->create(['id' => 1]);

    expect(fn () => Setting::factory()->create(['id' => 2]))->toThrow(QueryException::class);
});

it('rejects overlapping appointments by protected resource', function (string $resource) {
    $professional = Professional::factory()->create();
    $patient = Patient::factory()->create();
    $room = Room::factory()->create();
    $specialty = Specialty::factory()->create();

    $base = [
        'professional_id' => $professional->id,
        'patient_id' => $patient->id,
        'room_id' => $room->id,
        'specialty_id' => $specialty->id,
        'starts_at' => '2026-11-02 10:00:00+00',
        'ends_at' => '2026-11-02 10:30:00+00',
        'status' => AppointmentStatus::Booked,
    ];

    Appointment::factory()->create($base);

    $overlap = [
        ...$base,
        'professional_id' => Professional::factory()->create()->id,
        'patient_id' => Patient::factory()->create()->id,
        'room_id' => Room::factory()->create()->id,
        'starts_at' => '2026-11-02 10:15:00+00',
        'ends_at' => '2026-11-02 10:45:00+00',
    ];
    $overlap["{$resource}_id"] = $base["{$resource}_id"];

    expect(fn () => Appointment::factory()->create($overlap))->toThrow(QueryException::class);
})->with(['professional', 'patient', 'room']);

it('allows contiguous appointments', function () {
    $appointment = Appointment::factory()->create([
        'starts_at' => '2026-11-02 10:00:00+00',
        'ends_at' => '2026-11-02 10:30:00+00',
        'status' => AppointmentStatus::Booked,
    ]);

    $contiguous = Appointment::factory()->create([
        'professional_id' => $appointment->professional_id,
        'patient_id' => $appointment->patient_id,
        'room_id' => $appointment->room_id,
        'specialty_id' => $appointment->specialty_id,
        'starts_at' => '2026-11-02 10:30:00+00',
        'ends_at' => '2026-11-02 11:00:00+00',
        'status' => AppointmentStatus::Booked,
    ]);

    $this->assertModelExists($contiguous);
});

it('allows overlaps when either appointment is cancelled', function (AppointmentStatus $cancelledStatus) {
    $appointment = Appointment::factory()->create([
        'starts_at' => '2026-11-02 10:00:00+00',
        'ends_at' => '2026-11-02 10:30:00+00',
        'status' => AppointmentStatus::Booked,
    ]);

    $cancelled = Appointment::factory()->create([
        'professional_id' => $appointment->professional_id,
        'patient_id' => $appointment->patient_id,
        'room_id' => $appointment->room_id,
        'specialty_id' => $appointment->specialty_id,
        'starts_at' => '2026-11-02 10:15:00+00',
        'ends_at' => '2026-11-02 10:45:00+00',
        'status' => $cancelledStatus,
    ]);

    $this->assertModelExists($cancelled);
})->with([
    AppointmentStatus::CancelledByProfessional,
    AppointmentStatus::CancelledByCenter,
]);

it('allows overlapping appointments with null rooms when people differ', function () {
    $first = Appointment::factory()->create([
        'room_id' => null,
        'starts_at' => '2026-11-02 10:00:00+00',
        'ends_at' => '2026-11-02 10:30:00+00',
        'status' => AppointmentStatus::Booked,
    ]);
    $second = Appointment::factory()->create([
        'professional_id' => Professional::factory(),
        'patient_id' => Patient::factory(),
        'room_id' => null,
        'specialty_id' => $first->specialty_id,
        'starts_at' => '2026-11-02 10:15:00+00',
        'ends_at' => '2026-11-02 10:45:00+00',
        'status' => AppointmentStatus::Booked,
    ]);

    $this->assertModelExists($second);
});

it('rejects overlapping schedules by professional or room', function (string $resource) {
    $professional = Professional::factory()->create();
    $room = Room::factory()->create();
    $specialty = Specialty::factory()->create();
    $base = [
        'professional_id' => $professional->id,
        'room_id' => $room->id,
        'specialty_id' => $specialty->id,
        'weekday' => 1,
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'valid_from' => '2026-11-01',
        'valid_until' => '2026-12-31',
    ];

    Schedule::factory()->create($base);

    $overlap = [
        ...$base,
        'professional_id' => Professional::factory()->create()->id,
        'room_id' => Room::factory()->create()->id,
        'start_time' => '11:00:00',
        'end_time' => '13:00:00',
    ];
    $overlap["{$resource}_id"] = $base["{$resource}_id"];

    expect(fn () => Schedule::factory()->create($overlap))->toThrow(QueryException::class);
})->with(['professional', 'room']);

it('allows contiguous schedules', function () {
    $schedule = Schedule::factory()->create([
        'weekday' => 1,
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'valid_from' => '2026-11-01',
        'valid_until' => null,
    ]);

    $contiguous = Schedule::factory()->create([
        'professional_id' => $schedule->professional_id,
        'room_id' => $schedule->room_id,
        'specialty_id' => $schedule->specialty_id,
        'weekday' => 1,
        'start_time' => '12:00:00',
        'end_time' => '14:00:00',
        'valid_from' => '2026-11-01',
        'valid_until' => null,
    ]);

    $this->assertModelExists($contiguous);
});

it('rejects invalid appointment and exception time ranges', function (string $model, array $attributes) {
    expect(fn () => $model::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'empty appointment' => [Appointment::class, [
        'starts_at' => '2026-11-02 10:00:00+00',
        'ends_at' => '2026-11-02 10:00:00+00',
    ]],
    'inverted schedule exception' => [ScheduleException::class, [
        'starts_at' => '2026-11-02 11:00:00+00',
        'ends_at' => '2026-11-02 10:00:00+00',
    ]],
]);

it('rejects invalid schedule ranges and weekdays', function (array $attributes) {
    expect(fn () => Schedule::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'empty time range' => [[
        'start_time' => '10:00:00',
        'end_time' => '10:00:00',
    ]],
    'inverted validity' => [[
        'valid_from' => '2026-12-31',
        'valid_until' => '2026-01-01',
    ]],
    'invalid weekday' => [['weekday' => 7]],
]);
