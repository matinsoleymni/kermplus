<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\UserProfileKeyboard;
use SergiX44\Nutgram\Nutgram;

class UserProfileHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $service = app(SubscriptionService::class);
        $referralService = app(ReferralService::class);
        $subscription = $service->getActiveSubscription($local);
        $local = $referralService->ensureUserHasCode($local);
        $referralCount = $referralService->totalReferrals($local);
        $threshold = $referralService->rewardThreshold();
        $availableRewards = $referralService->availableRewardCycles($local);
        $botUsername = ltrim((string) env('TELEGRAM_BOT_USERNAME', ''), '@');
        $link = $botUsername !== ''
            ? "https://t.me/{$botUsername}?start={$local->referral_code}"
            : 'لینک دعوت موجود نیست';

        $msg = "❁ نام : {$local->name}\n";
        $msg .= "❁ یوزرنیم : " . ($tgUser?->username ?? 'ندارد') . "\n";
        $msg .= "❁ ایدی عددی : {$local->id}\n";
        $msg .= "❁ تعداد دعوت ها: {$referralCount} نفر\n";
        $msg .= "❁ پاداش قابل دریافت: {$availableRewards}\n";
        $msg .= "❁ قانون پاداش: هر {$threshold} دعوت = 1 اشتراک هدیه\n";
        $msg .= "❁ لینک دعوت اختصاصی : {$link}\n";

        if ($subscription) {
            $msg .= "❁ نوع اشتراک : {$subscription->plan->name}";
        } else {
            $msg .= "❁ نوع اشتراک : رایگان";
        }

        $bot->editMessageText($msg, reply_markup: UserProfileKeyboard::make($subscription !== null));
    }
}
