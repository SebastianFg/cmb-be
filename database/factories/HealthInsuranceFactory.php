<?php

namespace Database\Factories;

use App\Models\HealthInsurance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HealthInsurance> */
class HealthInsuranceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'plan' => fake()->optional()->bothify('Plan ??-###'),
        ];
    }
}
