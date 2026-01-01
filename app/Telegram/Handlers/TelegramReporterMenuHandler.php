<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\TelegramReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class TelegramReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\n🟦 ریپورتر تلگرام 🤝\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: TelegramReporterMenuKeyboard::make());
    }
}
