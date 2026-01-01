<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\ReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class ReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\nبه بخش ریپورتر 📝 خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: ReporterMenuKeyboard::make());
    }
}
