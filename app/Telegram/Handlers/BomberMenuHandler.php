<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\BomberMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class BomberMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\nبه بخش بمبر 💣 خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: BomberMenuKeyboard::make());
    }
}
