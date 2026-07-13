<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Device;
use App\Models\DispatchedEvent;
use App\Models\EventDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDelivery>
 */
class EventDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'dispatched_event_id' => DispatchedEvent::factory()->for($user),
            'device_id' => Device::factory()->for($user),
            'status' => DeliveryStatus::Sent,
            'sent_at' => now(),
            'acknowledged_at' => null,
            'error' => null,
        ];
    }

    /**
     * The delivery has been acknowledged by the app.
     */
    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
    }
}
