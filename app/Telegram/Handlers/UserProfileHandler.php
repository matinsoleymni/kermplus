<?php

namespace App\Telegram\Handlers;

use App\Models\User;
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
        $subscription = $service->getActiveSubscription($local);
        $referralCount = $local->referrals()->count();

        $msg = "❁ نام : {$local->name}\n";
        $msg .= "❁ یوزرنیم : " . ($tgUser?->username ?? 'ندارد') . "\n";
        $msg .= "❁ ایدی عددی : {$local->id}\n";
        $msg .= "❁ تعداد دعوت ها: {$referralCount} نفر\n";
        $msg .= "❁ لینک دعوت اختصاصی : https://t.me/KermPlusBot?start={$local->referral_code}\n";

        if ($subscription) {
            $msg .= "❁ نوع اشتراک : {$subscription->plan->name}";
        } else {
            $msg .= "❁ نوع اشتراک : رایگان";
        }

        $bot->editMessageText($msg, reply_markup: UserProfileKeyboard::make());
    }
}
