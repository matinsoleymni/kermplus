<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'duration_days',
        'max_sms_per_day',
        'max_email_per_day',
        'max_requests_per_day',
        'features',
        'is_active',
    ];

    protected $casts = [
        'features' => 'json',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * دریافت تمام ویژگی‌های این پلن
     */
    public function getFeatures(): array
    {
        return $this->features ?? [];
    }

    /**
     * بررسی اینکه آیا این پلن برای SMS استفاده شود
     */
    public function hasSmsFeature(): bool
    {
        return in_array('sms', $this->getFeatures());
    }

    /**
     * بررسی اینکه آیا این پلن برای Email استفاده شود
     */
    public function hasEmailFeature(): bool
    {
        return in_array('email', $this->getFeatures());
    }
}
