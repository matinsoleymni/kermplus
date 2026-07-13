<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
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
            'fcm_token' => Str::random(140),
            'device_id' => fake()->uuid(),
            'manufacturer' => fake()->randomElement(['Samsung', 'Xiaomi', 'Google', 'OnePlus']),
            'model' => fake()->bothify('Model-##??'),
            'android_version' => fake()->randomElement(['12', '13', '14', '15']),
            'sdk_int' => fake()->numberBetween(31, 35),
            'app_version' => fake()->numerify('#.#.#'),
            'locale' => fake()->randomElement(['en_US', 'fa_IR']),
            'last_seen_at' => now(),
        ];
    }
}
