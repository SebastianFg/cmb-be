<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PushSubscription> */
class PushSubscriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => fake()->unique()->url().'/push/'.Str::uuid(),
            'public_key' => Str::random(88),
            'auth_token' => Str::random(24),
            'user_agent' => fake()->optional()->userAgent(),
            'last_success_at' => fake()->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
