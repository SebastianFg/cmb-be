<?php

namespace Database\Factories;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Room;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+6 months');

        return [
            'patient_id' => Patient::factory(),
            'professional_id' => Professional::factory(),
            'specialty_id' => Specialty::factory(),
            'room_id' => Room::factory(),
            'created_by' => null,
            'cancelled_by' => null,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+30 minutes'),
            'status' => AppointmentStatus::Booked,
            'source' => AppointmentSource::Portal,
            'confirmation_code' => Str::upper(Str::random(10)),
            'cancellation_reason' => null,
            'is_penalized' => false,
            'reason' => fake()->optional()->sentence(),
            'notes' => null,
            'attended_at' => null,
        ];
    }
}
