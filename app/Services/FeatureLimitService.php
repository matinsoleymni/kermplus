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

        // return true;

        if ($this->hasReporterAccess($user)) {
            if (!$user->isAdmin()) {
                $last = (clone $query)->latest()->first();
                if ($last && $last->created_at->gt($now->copy()->subHours(8))) {
                    return 'هر 8 ساعت فقط یک درخواست ریپورت میتونی ثبت کنی عزیزم 🥺♥️';
                }
            }

            if($user->isAdmin()) {

            }else {
                $todayCount = (clone $query)
                    ->whereDate('created_at', $now->toDateString())
                    ->count();
                if ($todayCount >= 3) {
                    return '⛔️ محدودیت روزانه ریپورتر (۳ درخواست) پر شده است.';
                }
            }
        } else {
            return "برای ریپورتر نیاز به اشتراک پرو یا پلاس داری";
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
        if (!$this->hasPlusOnlyAccess($user, SubscriptionService::FEATURE_HARASSER)) {
            return "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";
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

        if (!$user->hasPlusSubscription()) {
            return "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";
        }


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
     * Whitelist access: plus users only.
     */
    public function checkWhitelistAdditionLimit(User $user): ?string
    {
        if (!$this->hasPlusOnlyAccess($user, SubscriptionService::FEATURE_WHITELIST)) {
            return "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";
        }

        return null;
    }

    public function hasWhitelistAddition(User $user): bool
    {
        return UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WHITELIST_ADD)
            ->exists();
    }

    public function getWhitelistAddedTarget(User $user): ?string
    {
        return UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WHITELIST_ADD)
            ->latest('id')
            ->value('target');
    }

    public function updateWhitelistAddedTarget(User $user, string $target): void
    {
        UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WHITELIST_ADD)
            ->latest('id')
            ->first()
            ?->update(['target' => $target]);
    }

    public function recordWhitelistAddition(User $user, ?string $target = null): void
    {
        $this->record($user, self::TYPE_WHITELIST_ADD, 1, $target);
    }

    private function record(User $user, string $type, int $count, ?string $target = null): void
    {
        UsageRecord::create([
            'user_id' => $user->id,
            'type' => $type,
            'target' => $target,
            'count' => $count,
        ]);
    }

    private function hasReporterAccess(User $user): bool
    {
        return $this->subscriptionService->hasFeatureAccess($user, SubscriptionService::FEATURE_REPORTER);
    }

    private function hasPlusOnlyAccess(User $user, string $feature): bool
    {
        return $this->subscriptionService->hasFeatureAccess($user, $feature);
    }
}
