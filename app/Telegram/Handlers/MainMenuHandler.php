<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\MainMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class MainMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);

        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;
        $hasActiveSubscription = $local
            ? app(SubscriptionService::class)->hasActiveSubscription($local)
            : false;

        $bot->editMessageText('❀ کرم پلاس ❀

اگه کسی اذیتت کرده ...
با ربات ما توهم می‌تونی حسابی اذیتش کنی :)

برای شروع یکی از گزینه‌های زیر رو انتخاب کن:', reply_markup: MainMenuKeyboard::make($hasActiveSubscription));
    }
}
