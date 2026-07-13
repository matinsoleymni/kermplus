<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Models\Device;
use App\Models\DispatchedEvent;
use App\Models\User;
use App\Services\Fcm\FcmSender;

/**
 * Dispatches an event to an owner's devices over FCM and records the outcome.
 *
 * One DispatchedEvent is created per call with an EventDelivery row per target
 * device. Each FCM message carries its delivery_id so the receiving app can
 * acknowledge it back, turning aggregate counts into per-device tracking.
 */
class EventDispatcher
{
    public function __construct(private readonly FcmSender $fcm) {}

    /**
     * Send an event to one of the owner's devices, or to all of them.
     *
     * @param  mixed  $data  Arbitrary payload; encoded to a string for the FCM data frame.
     */
    public function dispatch(User $owner, string $event, mixed $data = null, ?Device $device = null): DispatchedEvent
    {
        $devices = $device !== null
            ? collect([$device])
            : $owner->devices()->get();

        $encodedData = is_string($data) || $data === null
            ? (string) $data
            : (string) json_encode($data, JSON_UNESCAPED_UNICODE);

        $dispatched = $owner->dispatchedEvents()->create([
            'device_id' => $device?->id,
            'event' => $event,
            'payload' => $data,
            'targeted_count' => $devices->count(),
        ]);

        $successCount = 0;

        foreach ($devices as $target) {
            $delivery = $dispatched->deliveries()->create([
                'user_id' => $owner->id,
                'device_id' => $target->id,
                'status' => DeliveryStatus::Pending,
            ]);

            $result = $this->fcm->send($target->fcm_token, [
                'event' => $event,
                'data' => $encodedData,
                'delivery_id' => (string) $delivery->id,
            ]);

            if ($result->successful) {
                $successCount++;
                $delivery->update(['status' => DeliveryStatus::Sent, 'sent_at' => now()]);
            } else {
                $delivery->update(['status' => DeliveryStatus::Failed, 'error' => $result->error]);
            }
        }

        $dispatched->update([
            'success_count' => $successCount,
            'failure_count' => $devices->count() - $successCount,
        ]);

        return $dispatched;
    }
}
