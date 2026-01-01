<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'started_at',
        'expires_at',
        'is_active',
        'auto_renew',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'auto_renew' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    /**
     * بررسی اینکه آیا اشتراک فعال است
     */
    public function isActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // lifetime
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isFuture();
    }

    /**
     * بررسی اینکه آیا اشتراک منقضی شده است
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * دریافت تعداد روزهای باقی‌مانده
     */
    public function getRemainingDays(): int
    {
        if (!$this->isActive()) {
            return 0;
        }
        if ($this->expires_at === null) {
            return PHP_INT_MAX;
        }
        return (int) $this->expires_at->diffInDays(Carbon::now());
    }

    /**
     * تمدید اشتراک
     */
    public function renew(?int $adminId = null): bool
    {
        if (!$this->plan) {
            return false;
        }

        $this->expires_at = $this->expires_at->addDays($this->plan->duration_days);
        $this->is_active = true;

        SubscriptionHistory::create([
            'subscription_id' => $this->id,
            'action' => 'renewed',
            'description' => $adminId ? "اشتراک تمدید شد توسط ادمین (ID: {$adminId})" : "اشتراک تمدید شد توسط سیستم خودکار",
            'created_by' => $adminId,
        ]);

        return $this->save();
    }

    /**
     * لغو اشتراک
     */
    public function cancel(?int $adminId = null): bool
    {
        $this->is_active = false;
        $this->auto_renew = false;

        SubscriptionHistory::create([
            'subscription_id' => $this->id,
            'action' => 'cancelled',
            'description' => $adminId ? "اشتراک لغو شد توسط ادمین (ID: {$adminId})" : "اشتراک لغو شد",
            'created_by' => $adminId,
        ]);

        return $this->save();
    }

    /**
     * ارتقاء به پلن دیگری
     */
    public function upgradeTo(SubscriptionPlan $newPlan, ?int $adminId = null): bool
    {
        $oldPlan = $this->plan;
        $this->subscription_plan_id = $newPlan->id;

        SubscriptionHistory::create([
            'subscription_id' => $this->id,
            'action' => 'upgraded',
            'description' => ($adminId ? "ارتقاء از {$oldPlan->name} به {$newPlan->name} توسط ادمین (ID: {$adminId})" : "ارتقاء از {$oldPlan->name} به {$newPlan->name}"),
            'created_by' => $adminId,
        ]);

        return $this->save();
    }

    /**
     * کاهش پلن
     */
    public function downgradeTo(SubscriptionPlan $newPlan, ?int $adminId = null): bool
    {
        $oldPlan = $this->plan;
        $this->subscription_plan_id = $newPlan->id;

        SubscriptionHistory::create([
            'subscription_id' => $this->id,
            'action' => 'downgraded',
            'description' => ($adminId ? "کاهش از {$oldPlan->name} به {$newPlan->name} توسط ادمین (ID: {$adminId})" : "کاهش از {$oldPlan->name} به {$newPlan->name}"),
            'created_by' => $adminId,
        ]);

        return $this->save();
    }
}
