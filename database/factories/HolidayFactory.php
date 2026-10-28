<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
class HolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
            'name' => fake()->sentence(3),
            'is_nationwide' => true,
        ];
    }
}
