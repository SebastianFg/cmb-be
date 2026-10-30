<?php

namespace Database\Seeders;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ExceptionType;
use App\Models\Appointment;
use App\Models\HealthInsurance;
use App\Models\Nationality;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Role;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de ejemplo de la agenda: especialidades, consultorios, profesionales
 * con sus horarios y turnos desde hace 35 días hasta dentro de 21, para que
 * el panel tenga indicadores reales con qué trabajar.
 */
class SchedulingSeeder extends Seeder
{
    /** @var list<array{name: string, title: string, first: string, last: string, license: string}> */
    private const PROFESSIONALS = [
        ['name' => 'Paula Aguirre', 'title' => 'Dra.', 'first' => 'Paula', 'last' => 'Aguirre', 'license' => 'M.N. 112.045'],
        ['name' => 'Andrés Paz', 'title' => 'Dr.', 'first' => 'Andrés', 'last' => 'Paz', 'license' => 'M.N. 104.388'],
        ['name' => 'Hernán Vidal', 'title' => 'Dr.', 'first' => 'Hernán', 'last' => 'Vidal', 'license' => 'M.N. 99.512'],
        ['name' => 'Marcos Iriarte', 'title' => 'Dr.', 'first' => 'Marcos', 'last' => 'Iriarte', 'license' => 'M.N. 87.230'],
        ['name' => 'Lucía Márquez', 'title' => 'Dra.', 'first' => 'Lucía', 'last' => 'Márquez', 'license' => 'M.N. 121.907'],
    ];

    /** @var list<string> */
    private const SPECIALTIES = [
        'Clínica médica',
        'Pediatría',
        'Cardiología',
        'Dermatología',
        'Nutrición',
    ];

    /** Motivos de consulta de ejemplo, para que la agenda muestre algo. */
    private const REASONS = [
        'Control anual',
        'Dolor de garganta y fiebre',
        'Renovación de receta',
        'Control de presión',
        'Resultados de estudios',
        'Apto físico',
    ];

    public function run(): void
    {
        Setting::query()->updateOrCreate(['id' => 1], [
            'center_name' => 'Centro Médico Belgrano',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'cancellation_window_minutes' => 1440,
            'cancellation_penalty_days' => 7,
            'reminder_lead_minutes' => 1440,
            'no_show_alert_threshold' => 8,
            'booking_horizon_days' => 30,
        ]);

        $specialties = collect(self::SPECIALTIES)->map(
            fn (string $name): Specialty => Specialty::query()->firstOrCreate(['name' => $name]),
        );

        $rooms = collect(range(1, 5))->map(
            fn (int $number): Room => Room::query()->firstOrCreate(
                ['name' => sprintf('C-%02d', $number)],
                ['floor' => 2],
            ),
        );

        $professionalRole = Role::query()->where('name', 'professional')->sole();
        $professionals = $this->seedProfessionals($specialties, $professionalRole);
        $this->seedSchedules($professionals, $specialties, $rooms);

        $patients = $this->seedPatients();
        $this->seedAppointments($professionals, $patients);
        $this->seedAbsence($professionals);
    }

    /**
     * @param  Collection<int, Specialty>  $specialties
     * @return Collection<int, Professional>
     */
    private function seedProfessionals($specialties, Role $role)
    {
        return collect(self::PROFESSIONALS)->values()->map(function (array $row, int $index) use ($specialties, $role): Professional {
            $slug = str($row['last'])->ascii()->lower()->value();

            $user = User::query()->updateOrCreate(
                ['email' => "{$slug}@cmbelgrano.com.ar"],
                [
                    'name' => "{$row['title']} {$row['first']} {$row['last']}",
                    'password' => Hash::make('Belgrano#2026$Seg'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
            $user->roles()->syncWithoutDetaching([$role->id]);

            $professional = Professional::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['license_number' => $row['license']],
            );
            $professional->specialties()->sync([$specialties[$index % $specialties->count()]->id]);

            return $professional;
        });
    }

    /**
     * Cada profesional atiende de lunes a viernes, media jornada.
     */
    private function seedSchedules($professionals, $specialties, $rooms): void
    {
        $professionals->each(function (Professional $professional, int $index) use ($specialties, $rooms): void {
            $specialty = $specialties[$index % $specialties->count()];
            $room = $rooms[$index % $rooms->count()];
            $morning = $index % 2 === 0;

            foreach (range(1, 5) as $weekday) {
                Schedule::query()->updateOrCreate(
                    ['professional_id' => $professional->id, 'weekday' => $weekday],
                    [
                        'specialty_id' => $specialty->id,
                        'room_id' => $room->id,
                        'start_time' => $morning ? '08:00' : '14:00',
                        'end_time' => $morning ? '13:00' : '19:00',
                        'slot_duration_minutes' => 30,
                        'valid_from' => CarbonImmutable::now()->subYear()->toDateString(),
                        'valid_until' => null,
                    ],
                );
            }
        });
    }

    /** @return Collection<int, Patient> */
    private function seedPatients()
    {
        $existing = Patient::query()->count();

        if ($existing >= 25) {
            return Patient::query()->limit(40)->get();
        }

        $nationality = Nationality::query()->firstOrCreate(['name' => 'Argentina'], ['iso_code' => 'AR']);
        $insurances = HealthInsurance::query()->get();

        Patient::factory()
            ->count(25 - $existing)
            ->sequence(fn ($sequence) => [
                'nationality_id' => $nationality->id,
                'health_insurance_id' => $insurances->random()->id,
            ])
            ->create();

        return Patient::query()->limit(40)->get();
    }

    /**
     * Treinta turnos repartidos a propósito: unos pocos hoy, algunos del mes
     * pasado para alimentar ausentismo y ocupación, y otros próximos para la
     * alerta de turnos sin confirmar. Alcanza para que el panel muestre todo
     * sin llenar la base de datos de ejemplo.
     */
    private function seedAppointments($professionals, $patients): void
    {
        if (Appointment::query()->exists()) {
            return;
        }

        $timezone = Setting::query()->value('timezone') ?? 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $usedCodes = [];

        // Un día no puede repetirse: la base tiene una restricción de exclusión
        // que impide dos turnos solapados del mismo profesional.
        $plan = [];
        $plan[$today->toDateString()] = ['day' => $today, 'count' => 8];

        foreach ([-28, -21, -14, -7, -3, -2] as $offset) {
            $day = $this->workingDay($today->addDays($offset), -1);
            $plan[$day->toDateString()] ??= ['day' => $day, 'count' => 2];
        }

        foreach ([1, 2, 4, 6] as $offset) {
            $day = $this->workingDay($today->addDays($offset), 1);
            $plan[$day->toDateString()] ??= ['day' => $day, 'count' => 2];
        }

        $rows = [];
        $seed = 0;

        foreach ($plan as $entry) {
            $rows = array_merge(
                $rows,
                $this->rowsFor($professionals, $patients, $entry['day'], $entry['count'], $usedCodes, $seed),
            );
            $seed++;
        }

        $now = now();

        // `insert()` no pasa por los casts del modelo: si las fechas van sin
        // offset, Postgres las lee en su propio huso y los turnos se corren.
        Appointment::query()->insert(array_map(
            fn (array $row): array => [
                ...$row,
                'starts_at' => $row['starts_at']->toIso8601String(),
                'ends_at' => $row['ends_at']->toIso8601String(),
                'status' => $row['status']->value,
                'source' => $row['source']->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor($professionals, $patients, CarbonImmutable $day, int $howMany, array &$usedCodes, int $seed = 0): array
    {
        $rows = [];
        $today = CarbonImmutable::now()->startOfDay();

        for ($i = 0; $i < $howMany; $i++) {
            $professional = $professionals[$i % $professionals->count()];
            $schedule = $professional->schedules()->where('weekday', $day->dayOfWeek)->first();

            if (! $schedule) {
                continue;
            }

            $hour = (int) substr((string) $schedule->start_time, 0, 2);
            $starts = $day->setTime($hour, 0)->addMinutes(30 * intdiv($i, $professionals->count()));
            $ends = $starts->addMinutes(30);
            $patient = $patients[($i + $seed * 3) % $patients->count()];

            $rows[] = [
                'patient_id' => $patient->id,
                'professional_id' => $professional->id,
                'specialty_id' => $schedule->specialty_id,
                'room_id' => $schedule->room_id,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'status' => $this->statusFor($day->lessThan($today) ? -1 : 0, $i + $seed, $schedule->specialty_id),
                'source' => $i % 3 === 0 ? AppointmentSource::Portal : AppointmentSource::Desk,
                'confirmation_code' => $this->newCode($usedCodes),
                'reason' => self::REASONS[($i + $seed) % count(self::REASONS)],
                'is_penalized' => false,
            ];
        }

        return $rows;
    }

    /**
     * Corre la fecha al día hábil más cercano. Las pasadas se mueven hacia
     * atrás para que no terminen pisando a las futuras.
     */
    private function workingDay(CarbonImmutable $day, int $direction): CarbonImmutable
    {
        while ($day->dayOfWeek === 0 || $day->dayOfWeek === 6) {
            $day = $day->addDays($direction);
        }

        return $day;
    }

    private function statusFor(int $offset, int $index, int $specialtyId): AppointmentStatus
    {
        if ($offset >= 0) {
            return $index % 3 === 0 ? AppointmentStatus::Confirmed : AppointmentStatus::Booked;
        }

        // Dermatología falta más, para que la alerta de ausentismo tenga qué mostrar.
        $noShowEvery = $specialtyId % 4 === 0 ? 2 : 5;

        if ($index % $noShowEvery === 0) {
            return AppointmentStatus::NoShow;
        }

        return $index % 7 === 0 ? AppointmentStatus::CancelledByCenter : AppointmentStatus::Attended;
    }

    private function seedAbsence($professionals): void
    {
        if (ScheduleException::query()->exists()) {
            return;
        }

        $timezone = Setting::query()->value('timezone') ?? 'UTC';
        $day = CarbonImmutable::now($timezone)->addDays(2)->startOfDay();

        while ($day->dayOfWeek === 0 || $day->dayOfWeek === 6) {
            $day = $day->addDay();
        }

        ScheduleException::query()->create([
            'professional_id' => $professionals->first()->id,
            'starts_at' => $day->setTime(11, 0),
            'ends_at' => $day->setTime(12, 0),
            'type' => ExceptionType::Absence,
            'reason' => 'Congreso de pediatría',
        ]);
    }

    /** @param array<string, bool> $used */
    private function newCode(array &$used): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $code = 'TRN-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (isset($used[$code]));

        $used[$code] = true;

        return $code;
    }
}
