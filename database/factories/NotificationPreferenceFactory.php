<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationPreference> */
class NotificationPreferenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'notifiable_type' => 'users',
            'notifiable_id' => UserFactory::new(),
            'type' => fake()->randomElement(NotificationType::cases()),
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'is_enabled' => true,
        ];
    }
}
