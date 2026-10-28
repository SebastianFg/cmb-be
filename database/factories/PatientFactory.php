<?php

namespace Database\Factories;

use App\Models\HealthInsurance;
use App\Models\Nationality;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Patient> */
class PatientFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'health_insurance_id' => HealthInsurance::factory(),
            'nationality_id' => Nationality::factory(),
            'document_number' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-100 years', '-1 year')->format('Y-m-d'),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'google_id' => null,
            'email_verified_at' => now(),
            'member_number' => fake()->optional()->numerify('##########'),
            'blocked_until' => null,
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes): array => ['password' => null]);
    }
}
