<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\MainMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class MainMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);

        $bot->editMessageText('❀ کرم پلاس ❀

اگه کسی اذیتت کرده ...
با ربات ما توهم می‌تونی حسابی اذیتش کنی :)

برای شروع یکی از گزینه‌های زیر رو انتخاب کن:', reply_markup: MainMenuKeyboard::make());
    }
}
