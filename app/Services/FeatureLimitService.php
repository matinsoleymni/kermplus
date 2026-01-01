<?php

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\User;
use Carbon\Carbon;

class FeatureLimitService
{
    public const TYPE_REPORTER = 'reporter';
    public const TYPE_HARASSER = 'harasser';
    public const TYPE_NEGATIVE_REACTION = 'negative_reaction';
    public const TYPE_WHITELIST_ADD = 'whitelist_add';

    public function __construct(private SubscriptionService $subscriptionService) {}

    /**
     * Verify reporter usage rules.
     */
    public function checkReporterLimit(User $user): ?string
    {
        $now = Carbon::now();
        $query = UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_REPORTER);

        if ($this->isPlus($user)) {
            $last = (clone $query)->latest()->first();
            if ($last && $last->created_at->gt($now->copy()->subHours(8))) {
                return 'هر 8 ساعت فقط یک درخواست ریپورت میتونی ثبت کنی عزیزم 🥺♥️';
            }

            $todayCount = (clone $query)
                ->whereDate('created_at', $now->toDateString())
                ->count();
            if ($todayCount >= 3) {
                return '⛔️ محدودیت روزانه ریپورتر (۳ درخواست) پر شده است.';
            }
        } else {
            $monthCount = (clone $query)
                ->where('created_at', '>=', $now->copy()->startOfMonth())
                ->count();
            if ($monthCount >= 1) {
                return '⛔️ کاربران عادی ماهی یکبار می‌توانند ریپورت ثبت کنند.';
            }
        }

        return null;
    }

    public function recordReporterUsage(User $user): void
    {
        $this->record($user, self::TYPE_REPORTER, 1);
    }

    /**
     * Verify harasser/annoying builder usage (مزاحم‌ساز).
     */
    public function checkHarasserLimit(User $user): ?string
    {
        if (!$this->isPlus($user)) {
            return "❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس🎗\" از طریق دکمه های ربات اقدام کنید.";
        }

        $todayCount = UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_HARASSER)
            ->whereDate('created_at', Carbon::now()->toDateString())
            ->count();

        if ($todayCount >= 1) {
            return '⛔️ مزاحم‌ساز برای اکانت‌های پلاس روزی یک‌بار قابل استفاده است.';
        }

        return null;
    }

    public function recordHarasserUsage(User $user): void
    {
        $this->record($user, self::TYPE_HARASSER, 1);
    }

    /**
     * Negative reaction limit: max 5 posts per day.
     */
    public function checkNegativeReactionLimit(User $user, int $count = 1): ?string
    {
        $usedToday = UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_NEGATIVE_REACTION)
            ->whereDate('created_at', Carbon::now()->toDateString())
            ->sum('count');

        if (($usedToday + $count) > 5) {
            return '⛔️ حداکثر ۵ ری‌اکشن منفی در روز می‌توانید ثبت کنید.';
        }

        return null;
    }

    public function recordNegativeReaction(User $user, int $count = 1): void
    {
        $this->record($user, self::TYPE_NEGATIVE_REACTION, $count);
    }

    /**
     * Whitelist additions: plus users, once only.
     */
    public function checkWhitelistAdditionLimit(User $user): ?string
    {
        if (!$this->isPlus($user)) {
            return "❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس🎗\" از طریق دکمه های ربات اقدام کنید.";
        }

        $alreadyAdded = UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WHITELIST_ADD)
            ->exists();

        if ($alreadyAdded) {
            return '⛔️ هر اکانت پلاس فقط یک بار می‌تواند به وایت‌لیست اضافه کند.';
        }

        return null;
    }

    public function recordWhitelistAddition(User $user): void
    {
        $this->record($user, self::TYPE_WHITELIST_ADD, 1);
    }

    private function record(User $user, string $type, int $count): void
    {
        UsageRecord::create([
            'user_id' => $user->id,
            'type' => $type,
            'count' => $count,
        ]);
    }

    private function isPlus(User $user): bool
    {
        return $this->subscriptionService->hasActiveSubscription($user);
    }
}
