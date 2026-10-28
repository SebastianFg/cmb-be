<?php

namespace Database\Factories;

use App\Models\MedicalEntry;
use App\Models\MedicalRecord;
use App\Models\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MedicalEntry> */
class MedicalEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'medical_record_id' => MedicalRecord::factory(),
            'appointment_id' => null,
            'professional_id' => Professional::factory(),
            'reason' => fake()->optional()->sentence(),
            'diagnosis' => fake()->optional()->paragraph(),
            'treatment' => fake()->optional()->paragraph(),
            'observations' => fake()->optional()->paragraph(),
            'recorded_at' => fake()->dateTimeBetween('-2 years', 'now'),
        ];
    }
}
