<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'center_name' => fake()->company(),
            'timezone' => 'America/Argentina/Buenos_Aires',
            'cancellation_window_minutes' => 1440,
            'cancellation_penalty_days' => 7,
            'reminder_lead_minutes' => 1440,
            'no_show_alert_threshold' => 20,
        ];
    }
}
