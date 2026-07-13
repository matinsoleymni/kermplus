<?php

namespace App\Http\Resources;

use App\Enums\DeliveryStatus;
use App\Models\DispatchedEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DispatchedEvent
 */
class DispatchedEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'device_id' => $this->device_id,
            'targeted_count' => $this->targeted_count,
            'success_count' => $this->success_count,
            'failure_count' => $this->failure_count,
            'acknowledged_count' => $this->acknowledged_count ?? $this->deliveries()
                ->where('status', DeliveryStatus::Acknowledged)
                ->count(),
            'created_at' => $this->created_at,
        ];
    }
}
