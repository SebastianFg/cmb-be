<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Schedule> */
class ScheduleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'professional_id' => Professional::factory(),
            'specialty_id' => Specialty::factory(),
            'room_id' => Room::factory(),
            'weekday' => fake()->numberBetween(0, 6),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_duration_minutes' => fake()->randomElement([15, 20, 30, 45, 60]),
            'valid_from' => now()->toDateString(),
            'valid_until' => null,
        ];
    }
}
