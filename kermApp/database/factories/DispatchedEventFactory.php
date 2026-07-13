<?php

namespace Database\Factories;

use App\Models\DispatchedEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispatchedEvent>
 */
class DispatchedEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => null,
            'event' => fake()->randomElement(['message', 'update', 'ping']),
            'payload' => fake()->sentence(),
            'targeted_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
        ];
    }
}
