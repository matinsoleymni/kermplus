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
        'price_usd',
        'price_irr',
        'price_stars',
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
        'price_usd' => 'decimal:2',
        'price_irr' => 'integer',
        'price_stars' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $plan): void {
            if ($plan->isDirty('price_usd')) {
                // explicit USD updates should also update legacy `price`.
                $plan->price = $plan->price_usd;
                return;
            }

            if ($plan->isDirty('price')) {
                // old codepaths may still update only `price`.
                $plan->price_usd = $plan->price;
                return;
            }

            if ((float) ($plan->price_usd ?? 0) <= 0 && (float) ($plan->price ?? 0) > 0) {
                $plan->price_usd = $plan->price;
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function usdPrice(): float
    {
        $usd = (float) ($this->price_usd ?? 0);

        if ($usd > 0) {
            return $usd;
        }

        return (float) ($this->price ?? 0);
    }

    public function irrPrice(): int
    {
        return (int) ($this->price_irr ?? 0);
    }

    public function starsPrice(): int
    {
        $stars = (int) ($this->price_stars ?? 0);
        if ($stars > 0) {
            return $stars;
        }

        $rate = (float) config('payments.telegram_star_usd_value', 0.5);
        if ($rate <= 0) {
            $rate = 0.5;
        }

        return (int) ceil($this->usdPrice() / $rate);
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
