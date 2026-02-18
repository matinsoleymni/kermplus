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
     * بررسی دسترسی یک ویژگی مشخص در این پلن.
     * پشتیبانی از wildcard (*) برای دسترسی کامل.
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->getFeatures();
        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    /**
     * بررسی اینکه آیا این پلن برای SMS استفاده شود
     */
    public function hasSmsFeature(): bool
    {
        return $this->hasFeature('sms');
    }

    /**
     * بررسی اینکه آیا این پلن برای Email استفاده شود
     */
    public function hasEmailFeature(): bool
    {
        return $this->hasFeature('email');
    }
}
