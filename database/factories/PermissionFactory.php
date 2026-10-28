<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $module = fake()->word();

        return [
            'name' => fake()->unique()->numerify($module.'.action-####'),
            'module' => $module,
            'description' => fake()->optional()->sentence(),
        ];
    }
}
