<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class ReferralService
{
    /**
     * تولید کد یکتا برای لینک دعوت
     */
    public function generateUniqueCode(int $length = 8): string
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * اطمینان از اینکه کاربر کد رفرال دارد
     */
    public function ensureUserHasCode(User $user): User
    {
        if (!$user->referral_code) {
            $user->referral_code = $this->generateUniqueCode();
            $user->save();
        }

        return $user;
    }

    public function rewardThreshold(): int
    {
        $threshold = (int) config('services.referral.reward_threshold', 20);
        return $threshold > 0 ? $threshold : 20;
    }

    public function totalReferrals(User $user): int
    {
        return $user->referrals()->count();
    }

    public function totalRewardCycles(User $user): int
    {
        return (int) floor($this->totalReferrals($user) / $this->rewardThreshold());
    }

    public function availableRewardCycles(User $user): int
    {
        $redeemed = (int) ($user->referrals_redeemed ?? 0);
        return max(0, $this->totalRewardCycles($user) - $redeemed);
    }

    public function referralsUntilNextReward(User $user): int
    {
        $threshold = $this->rewardThreshold();
        $current = $this->totalReferrals($user) % $threshold;
        return $current === 0 ? 0 : $threshold - $current;
    }

    public function consumeOneRewardCycle(User $user): void
    {
        $user->increment('referrals_redeemed');
        $user->refresh();
    }
}
