<?php

namespace App\Models;

use Database\Factories\DispatchedEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'device_id',
    'event',
    'payload',
    'targeted_count',
    'success_count',
    'failure_count',
])]
class DispatchedEvent extends Model
{
    /** @use HasFactory<DispatchedEventFactory> */
    use HasFactory;

    /**
     * The owner that dispatched this event.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The single target device, when the event was not a broadcast.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The per-device deliveries created for this event.
     *
     * @return HasMany<EventDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(EventDelivery::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
