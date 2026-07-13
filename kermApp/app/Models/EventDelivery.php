<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Database\Factories\EventDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'dispatched_event_id',
    'device_id',
    'status',
    'error',
    'sent_at',
    'acknowledged_at',
])]
class EventDelivery extends Model
{
    /** @use HasFactory<EventDeliveryFactory> */
    use HasFactory;

    /**
     * The owner that dispatched the event.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The event this delivery belongs to.
     *
     * @return BelongsTo<DispatchedEvent, $this>
     */
    public function dispatchedEvent(): BelongsTo
    {
        return $this->belongsTo(DispatchedEvent::class);
    }

    /**
     * The device this delivery targets.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
