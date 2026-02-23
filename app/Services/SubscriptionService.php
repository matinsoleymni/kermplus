<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public const FEATURE_BOMBER = 'bomber';
    public const FEATURE_REPORTER = 'reporter';
    public const FEATURE_HARASSER = 'harasser';
    public const FEATURE_WHITELIST = 'whitelist';

    /**
     * ایجاد اشتراک جدید برای کاربر
     */
    public function createSubscription(User $user, SubscriptionPlan $plan, ?int $daysFromNow = null, ?int $createdBy = null): Subscription
    {
        $subscription = new Subscription();
        $subscription->user_id = $user->id;
        $subscription->subscription_plan_id = $plan->id;
        $subscription->started_at = Carbon::now();
        $duration = $daysFromNow ?? $plan->duration_days;
        $subscription->expires_at = $duration && $duration > 0 ? Carbon::now()->addDays($duration) : null;
        $subscription->is_active = true;
        $subscription->auto_renew = false;
        $subscription->save();

        // ثبت در تاریخچه
        $subscription->history()->create([
            'action' => 'created',
            'description' => "اشتراک جدید به پلن {$plan->name} ایجاد شد",
            'created_by' => $createdBy,
        ]);

        DB::afterCommit(function () use ($user, $plan): void {
            app(SubscriptionActivationNotificationService::class)->notifyIfEligible($user, $plan);
        });

        return $subscription;
    }

    /**
     * بررسی اینکه کاربر دسترسی فعال دارد
     */
    public function hasActiveSubscription(User $user): bool
    {
        $subscription = $user->subscriptions()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->latest()
            ->first();

        return $subscription !== null;
    }

    /**
     * دریافت اشتراک فعال کاربر
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->with('plan')
            ->latest()
            ->first();
    }

    public function getActivePlan(User $user): ?SubscriptionPlan
    {
        return $this->getActiveSubscription($user)?->plan;
    }

    public function hasFeatureAccess(User $user, string $feature): bool
    {
        $plan = $this->getActivePlan($user);

        if (!$plan) {
            return false;
        }

        return $plan->hasFeature($feature);
    }

    public function isPlus(User $user): bool
    {
        $plan = $this->getActivePlan($user);
        return $plan !== null && mb_strtolower($plan->name) === 'plus';
    }

    /**
     * بررسی اینکه کاربر می‌تواند SMS ارسال کند (subscription یا مجانی)
     */
    public function canSendSms(User $user): bool
    {
        if ($this->hasFeatureAccess($user, self::FEATURE_BOMBER) || $this->hasFeatureAccess($user, 'sms')) {
            return true;
        }

        // اگر subscription نداره، می‌تواند یک بار مجانی استفاده کند
        return $user->canUseFreeSmS();
    }

    /**
     * بررسی اینکه کاربر می‌تواند Email ارسال کند (subscription یا مجانی)
     */
    public function canSendEmail(User $user): bool
    {
        if ($this->hasFeatureAccess($user, self::FEATURE_BOMBER) || $this->hasFeatureAccess($user, 'email')) {
            return true;
        }

        // اگر subscription نداره، می‌تواند یک بار مجانی استفاده کند
        return $user->canUseFreeEmail();
    }

    /**
     * بررسی حد روزانه برای SMS (فقط برای subscription)
     */
    public function checkSmsDailyLimit(User $user, int $count = 1): bool
    {
        // بمبرها نامحدود هستند؛ محدودیتی اعمال نمی‌شود.
        return true;
    }

    /**
     * بررسی حد روزانه برای Email (فقط برای subscription)
     */
    public function checkEmailDailyLimit(User $user, int $count = 1): bool
    {
        // بمبرها نامحدود هستند؛ محدودیتی اعمال نمی‌شود.
        return true;
    }

    /**
     * دریافت جزئیات اشتراک (برای نمایش به کاربر)
     */
    public function getSubscriptionDetails(User $user): array
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return [
                'is_active' => false,
                'message' => 'شما هیچ اشتراک فعالی ندارید',
            ];
        }

        return [
            'is_active' => true,
            'plan_name' => $subscription->plan->name,
            'started_at' => $subscription->started_at->format('Y-m-d'),
            'expires_at' => $subscription->expires_at ? $subscription->expires_at->format('Y-m-d') : 'نامحدود',
            'remaining_days' => $subscription->expires_at ? $subscription->getRemainingDays() : 'نامحدود',
            'max_sms_per_day' => $subscription->plan->max_sms_per_day,
            'max_email_per_day' => $subscription->plan->max_email_per_day,
            'max_requests_per_day' => $subscription->plan->max_requests_per_day,
            'features' => $subscription->plan->getFeatures(),
            'auto_renew' => $subscription->auto_renew,
        ];
    }

    /**
     * تمدید خودکار اشتراکات منقضی‌شده
     */
    public function autoRenewExpiredSubscriptions(): int
    {
        $expiredSubscriptions = Subscription::where('auto_renew', true)
            ->where('expires_at', '<=', Carbon::now())
            ->where('is_active', true)
            ->with('plan')
            ->get();

        $renewed = 0;
        foreach ($expiredSubscriptions as $subscription) {
            if ($subscription->renew()) {
                $renewed++;
            }
        }

        return $renewed;
    }

    /**
     * دریافت تمام اشتراکات منقضی‌شده
     */
    public function getExpiredSubscriptions()
    {
        return Subscription::where('expires_at', '<=', Carbon::now())
            ->where('is_active', true)
            ->with(['user', 'plan'])
            ->get();
    }

    /**
     * ارتقاء یا کاهش اشتراک
     */
    public function changePlan(User $user, SubscriptionPlan $newPlan, ?int $adminId = null): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return false;
        }

        $oldPlanId = $subscription->subscription_plan_id;

        if ($oldPlanId > $newPlan->id) {
            // کاهش پلن
            return $subscription->downgradeTo($newPlan, $adminId);
        } else {
            // ارتقاء پلن
            return $subscription->upgradeTo($newPlan, $adminId);
        }
    }
}
