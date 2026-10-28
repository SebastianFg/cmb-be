<?php

namespace Database\Factories;

use App\Models\Nationality;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Nationality> */
class NationalityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->country(),
            'iso_code' => fake()->unique()->regexify('[A-Z]{3}'),
        ];
    }
}
