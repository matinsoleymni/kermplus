<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\RubikaReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class RubikaReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\n🟧 ریپورتر روبیکا 🤝\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: RubikaReporterMenuKeyboard::make());
    }
}
