<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\MobileKermRiziKeyboard;
use SergiX44\Nutgram\Nutgram;

class MobileKermRiziHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\nبه بخش کرم ریزی رو موبایل 📱 خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: MobileKermRiziKeyboard::make());
    }
}
