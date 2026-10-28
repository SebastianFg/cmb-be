<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Notification> */
class NotificationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'notifiable_type' => 'users',
            'notifiable_id' => UserFactory::new(),
            'appointment_id' => null,
            'type' => fake()->randomElement(NotificationType::cases()),
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'status' => NotificationStatus::Pending,
            'scheduled_for' => fake()->dateTimeBetween('now', '+1 month'),
            'sent_at' => null,
            'read_at' => null,
            'attempts' => 0,
        ];
    }
}
