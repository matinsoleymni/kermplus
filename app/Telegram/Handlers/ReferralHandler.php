<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\ReferralKeyboard;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReferralHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $data = $bot->callbackQuery()?->data ?? '';
        if ($data === 'referral_claim') {
            $this->claimReward($bot, $local);
            return;
        }

        $this->showReferralDashboard($bot, $local);
    }

    private function showReferralDashboard(Nutgram $bot, User $local): void
    {
        $referralService = app(ReferralService::class);
        $local = $referralService->ensureUserHasCode($local);

        $referralLink = $this->buildReferralLink($bot, $local->referral_code);
        $referralCount = $referralService->totalReferrals($local);
        $threshold = $referralService->rewardThreshold();
        $availableRewards = $referralService->availableRewardCycles($local);
        $remainingToNext = $referralService->referralsUntilNextReward($local);
        $shareText = "🔥 بیا توی ربات تست امنیت. با لینک من مستقیم وارد شو:\n{$referralLink}";

        $msg = "🎁 باشگاه دعوت دوستان\n\n";
        $msg .= "🔑 کد اختصاصی: {$local->referral_code}\n";
        $msg .= "🔗 لینک دعوت: {$referralLink}\n";
        $msg .= "👥 ورودی‌های ثبت‌شده: {$referralCount}\n\n";
        $msg .= "🎯 هر {$threshold} دعوت = 1 اشتراک هدیه\n";
        $msg .= "🎁 پاداش آماده دریافت: {$availableRewards}\n";

        if ($remainingToNext > 0) {
            $msg .= "📌 تا پاداش بعدی: {$remainingToNext} دعوت\n\n";
        } else {
            $msg .= "📌 شما به حد نصاب یک پاداش رسیده‌اید.\n\n";
        }

        $msg .= "برای دعوت کافی است این پیام را فروارد کنی یا از دکمه اشتراک‌گذاری استفاده کنی. لینک تو مستقیماً /start را با کد رفرال اجرا می‌کند.";

        $this->replyWithEditPreferred(
            $bot,
            $msg,
            ReferralKeyboard::make($referralLink, $shareText, $availableRewards > 0)
        );
    }

    private function claimReward(Nutgram $bot, User $local): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $referralService = app(ReferralService::class);
        $subscriptionService = app(SubscriptionService::class);

        $result = DB::transaction(function () use ($local, $referralService, $subscriptionService) {
            $lockedUser = User::query()->whereKey($local->id)->lockForUpdate()->first();
            if (!$lockedUser) {
                return ['ok' => false, 'message' => '❌ کاربر پیدا نشد.'];
            }

            if ($referralService->availableRewardCycles($lockedUser) < 1) {
                return ['ok' => false, 'message' => '⛔️ هنوز پاداش آماده دریافت ندارید.'];
            }

            if ($subscriptionService->hasActiveSubscription($lockedUser)) {
                return ['ok' => false, 'message' => '⛔️ برای دریافت هدیه رفرال، ابتدا باید اشتراک فعلی شما منقضی شود.'];
            }

            $rewardPlan = $this->resolveRewardPlan();
            if (!$rewardPlan) {
                return ['ok' => false, 'message' => '⛔️ پلن هدیه برای رفرال تنظیم نشده است.'];
            }

            $subscriptionService->createSubscription($lockedUser, $rewardPlan, $rewardPlan->duration_days, $lockedUser->id);
            $referralService->consumeOneRewardCycle($lockedUser);

            return ['ok' => true, 'message' => "✅ اشتراک هدیه «{$rewardPlan->name}» با موفقیت برای شما فعال شد."];
        });

        $bot->sendMessage($result['message']);
        $this->showReferralDashboard($bot, $local->fresh() ?? $local);
    }

    private function buildReferralLink(Nutgram $bot, string $referralCode): string
    {
        $botUsername = $bot->getMe()?->username ?? env('TELEGRAM_BOT_USERNAME');
        if ($botUsername) {
            $botUsername = ltrim($botUsername, '@');
            return "https://t.me/{$botUsername}?start={$referralCode}";
        }

        return "/start {$referralCode}";
    }

    private function resolveRewardPlan(): ?SubscriptionPlan
    {
        $planId = config('services.referral.reward_plan_id');
        if ($planId) {
            return SubscriptionPlan::query()
                ->where('is_active', true)
                ->find((int) $planId);
        }

        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->orderByDesc('duration_days')
            ->first();
    }

    private function replyWithEditPreferred(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->callbackQuery()?->message) {
            $bot->editMessageText($text, reply_markup: $keyboard);
            return;
        }

        $bot->sendMessage($text, reply_markup: $keyboard);
    }
}
