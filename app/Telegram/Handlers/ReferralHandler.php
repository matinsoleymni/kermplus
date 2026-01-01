<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\ReferralService;
use App\Telegram\Keyboards\ReferralKeyboard;
use SergiX44\Nutgram\Nutgram;

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

        $referralService = app(ReferralService::class);
        $local = $referralService->ensureUserHasCode($local);

        $referralLink = $this->buildReferralLink($bot, $local->referral_code);
        $referralCount = $local->referrals()->count();
        $shareText = "🔥 بیا توی ربات تست امنیت. با لینک من مستقیم وارد شو:\n{$referralLink}";

        $msg = "🎁 **باشگاه دعوت دوستان**\n\n";
        $msg .= "🔑 کد اختصاصی: `{$local->referral_code}`\n";
        $msg .= "🔗 لینک دعوت: {$referralLink}\n";
        $msg .= "👥 ورودی‌های ثبت‌شده: {$referralCount}\n\n";
        $msg .= "برای دعوت کافی است این پیام را فروارد کنی یا از دکمه اشتراک‌گذاری استفاده کنی. لینک تو مستقیماً /start را با کد رفرال اجرا می‌کند.";

        $bot->editMessageText(
            $msg,
            reply_markup: ReferralKeyboard::make($referralLink, $shareText)
        );
    }

    private function buildReferralLink(Nutgram $bot, string $referralCode): string
    {
        $botUsername = $bot->getMe()?->username ?? env('TELEGRAM_BOT_USERNAME');
        if ($botUsername) {
            return "https://t.me/{$botUsername}?start={$referralCode}";
        }

        return "/start {$referralCode}";
    }
}
