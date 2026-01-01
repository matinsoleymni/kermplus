<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\InstagramReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class InstagramReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\n🟥 ریپورتر اینستاگرام 🤝\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: InstagramReporterMenuKeyboard::make());
    }
}
