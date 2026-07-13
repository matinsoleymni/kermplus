<?php

namespace App\Http\Resources;

use App\Models\EventDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventDelivery
 */
class EventDeliveryResource extends JsonResource
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
            'dispatched_event_id' => $this->dispatched_event_id,
            'device_id' => $this->device_id,
            'status' => $this->status->value,
            'error' => $this->error,
            'sent_at' => $this->sent_at,
            'acknowledged_at' => $this->acknowledged_at,
        ];
    }
}
