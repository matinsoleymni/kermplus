<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\KermRiziKeyboard;
use SergiX44\Nutgram\Nutgram;

class KermRiziHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\nبه بخش کرم ریزی 🪱 خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: KermRiziKeyboard::make());
    }
}
