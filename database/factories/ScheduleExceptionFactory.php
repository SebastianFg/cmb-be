<?php

namespace Database\Factories;

use App\Enums\ExceptionType;
use App\Models\Professional;
use App\Models\ScheduleException;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleException> */
class ScheduleExceptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+1 year');

        return [
            'professional_id' => Professional::factory(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+4 hours'),
            'type' => fake()->randomElement(ExceptionType::cases()),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
